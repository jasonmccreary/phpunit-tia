<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\TextUI\Configuration\Registry;

/**
 * The bipartite test↔source edge map, affected() impact analysis, and
 * per-branch baselines (§4.3). Ported from Pest's Graph.php, keeping only
 * the generic core — framework-specific heuristics (Laravel migrations,
 * Blade, Inertia, arch tests) are dropped entirely; the one Laravel-specific
 * bit worth keeping generically (the sibling-directory fallback for PHP
 * files with zero coverage edges) is generalized here into an unconditional
 * default rather than a hardcoded Laravel path-prefix gate — see
 * applyUnknownSourceDirs(). Framework packages extend this via Resolver
 * (Contracts/Resolver.php) instead.
 */
final class Graph
{
    private const int SCHEMA_VERSION = 1;

    /** @var array<int, string> */
    private array $files = [];

    /** @var array<string, int> */
    private array $fileIds = [];

    /** @var array<string, array<int, int>> */
    private array $edges = [];

    /** @var array<string, mixed> */
    private array $fingerprint = [];

    /**
     * @var array<string, array{
     *     sha: ?string,
     *     tree: array<string, string>,
     *     results: array<string, array{status: int, message: string, time: float, assertions?: int, file?: string}>
     * }>
     */
    private array $baselines = [];

    private readonly string $projectRoot;

    /** @var array<string, string|false> */
    private array $realpathCache = [];

    /** @var list<Resolver> */
    private array $resolvers = [];

    private ?TestPaths $testPaths = null;

    public function __construct(string $projectRoot)
    {
        $real = @realpath($projectRoot);

        $this->projectRoot = $real !== false ? $real : $projectRoot;
    }

    /**
     * @param  list<Resolver>  $resolvers
     */
    public function setResolvers(array $resolvers): void
    {
        $this->resolvers = $resolvers;
    }

    /**
     * Injection seam for tests: TestPaths::fromProjectRoot() reads PHPUnit's
     * process-global Registry singleton, which reflects whatever config
     * actually bootstrapped the running suite — not $this->projectRoot.
     * That's correct in production (Graph is always used inside the real
     * run it's tracking) but makes affected() untestable against a scratch
     * project root without this override. Leave null to get real
     * TestPaths::fromProjectRoot() behavior.
     */
    public function setTestPaths(?TestPaths $testPaths): void
    {
        $this->testPaths = $testPaths;
    }

    public function link(string $testFile, string $sourceFile): void
    {
        $testRel = $this->relative($testFile);
        $sourceRel = $this->relative($sourceFile);

        if ($sourceRel === null || $testRel === null) {
            return;
        }

        if (! isset($this->fileIds[$sourceRel])) {
            $id = count($this->files);
            $this->files[$id] = $sourceRel;
            $this->fileIds[$sourceRel] = $id;
        }

        $this->edges[$testRel][] = $this->fileIds[$sourceRel];
    }

    /**
     * @param  array<string, array<int, string>>  $testToFiles
     */
    public function replaceEdges(array $testToFiles): void
    {
        foreach ($testToFiles as $testFile => $sources) {
            $testRel = $this->relative($testFile);

            if ($testRel === null) {
                continue;
            }

            $this->edges[$testRel] = [];

            foreach ($sources as $source) {
                $this->link($testFile, $source);
            }

            $this->edges[$testRel] = array_values(array_unique($this->edges[$testRel]));
        }
    }

    /**
     * Mark test files that executed under a recorded coverage session as
     * "known", seeding an empty edge set for any that produced zero project-
     * source edges. Without this, a test that covers no application source
     * never becomes an edge key, so knowsTest() reports it as unknown and it
     * re-runs on every TIA run. It's still re-run whenever its own file
     * changes, via applyTestFileChanges().
     *
     * Must only be called from the recording path, where coverage was
     * actually collected — otherwise a missing edge set could mean
     * "coverage was off", not "genuinely covered nothing".
     *
     * @param  array<int, string>  $testFiles  Absolute or project-relative test file paths.
     */
    public function markKnownTestFiles(array $testFiles): void
    {
        foreach ($testFiles as $testFile) {
            $rel = $this->relative($testFile);

            if ($rel === null) {
                continue;
            }

            if (! isset($this->edges[$rel])) {
                $this->edges[$rel] = [];
            }
        }
    }

    public function knowsTest(string $testFile): bool
    {
        $rel = $this->relative($testFile);

        return $rel !== null && isset($this->edges[$rel]);
    }

    /**
     * Public seam onto the same path-normalization relative() applies to
     * every edge/affected() key, so callers outside Graph (Tia's replay
     * decision) can translate a reflected test file into the identical key
     * space without duplicating the absolute/relative + vendor/-exclusion
     * logic here.
     */
    public function relativePath(string $path): ?string
    {
        return $this->relative($path);
    }

    /** @return array<int, string> */
    public function allTestFiles(): array
    {
        return array_keys($this->edges);
    }

    /** @return array<int, string> */
    public function allSourceFiles(): array
    {
        return array_values($this->files);
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    public function setFingerprint(array $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return $this->fingerprint;
    }

    /**
     * @param  array<int, string>  $changedFiles  Absolute or relative paths.
     * @return array<int, string>
     */
    public function affected(array $changedFiles): array
    {
        $relPaths = [];

        foreach ($changedFiles as $changedFile) {
            $rel = $this->relative($changedFile);

            if ($rel !== null) {
                $relPaths[] = $rel;
            }
        }

        $relPaths = array_values(array_unique($relPaths));
        $testPaths = $this->testPaths ?? TestPaths::fromProjectRoot($this->projectRoot);

        $affectedSet = [];

        $unknown = $this->applyPhpEdgeChanges($relPaths, $testPaths, $affectedSet);
        $this->applyTestFileChanges($relPaths, $testPaths, $affectedSet);
        $this->applyUnknownSourceDirs($unknown, $affectedSet);
        $this->applyResolvers($unknown, $affectedSet);

        return array_keys($affectedSet);
    }

    /**
     * Direct edge lookup: a changed file that's a known source dependency of
     * some test marks that test affected. Anything that doesn't match a
     * known edge (and isn't itself a test file) is returned for the sibling-
     * directory fallback and Resolver extension point to have a shot at.
     *
     * @param  list<string>  $relPaths
     * @param  array<string, true>  $affectedSet
     * @return list<string>
     */
    private function applyPhpEdgeChanges(array $relPaths, TestPaths $testPaths, array &$affectedSet): array
    {
        $changedIds = [];
        $unknown = [];

        foreach ($relPaths as $rel) {
            if (isset($this->fileIds[$rel])) {
                $changedIds[$this->fileIds[$rel]] = true;

                continue;
            }

            if ($testPaths->isTestFile($rel)) {
                continue;
            }

            $unknown[] = $rel;
        }

        // No `isset($affectedSet[$testFile])` short-circuit here, unlike the
        // passes that follow: this is the first thing `affected()` calls, so
        // the set is always empty on entry, and the only writer below is
        // followed by `break` on a key that is unique by definition. The guard
        // that used to sit here could never evaluate true. If the call order
        // ever changes so the set arrives populated, the worst case is
        // re-setting a key that is already `true`.
        foreach ($this->edges as $testFile => $ids) {
            foreach ($ids as $id) {
                if (isset($changedIds[$id])) {
                    $affectedSet[$testFile] = true;

                    break;
                }
            }
        }

        return $unknown;
    }

    /**
     * A changed file inside the configured test suites is itself the unit of
     * work — always run it (new untracked tests, edited tests, renames).
     *
     * @param  list<string>  $relPaths
     * @param  array<string, true>  $affectedSet
     */
    private function applyTestFileChanges(array $relPaths, TestPaths $testPaths, array &$affectedSet): void
    {
        foreach ($relPaths as $rel) {
            if (isset($affectedSet[$rel])) {
                continue;
            }

            if (! $testPaths->isTestFile($rel)) {
                continue;
            }

            if (! is_file($this->projectRoot.'/'.$rel)) {
                continue;
            }

            $affectedSet[$rel] = true;
        }
    }

    /**
     * New or never-covered PHP files (framework glue PHPUnit's static
     * coverage session never observed a test touching directly — a queued
     * job, an event listener, a console command) have no edge at all. Rather
     * than gate this behind a hardcoded framework path list like Pest does
     * for Laravel, core applies it unconditionally: any test with an edge to
     * *some other* file in the same directory as an unresolved change is
     * conservatively marked affected too. A false positive here just costs
     * an extra re-run; a false negative defeats the whole point of TIA.
     *
     * @param  list<string>  $unknown
     * @param  array<string, true>  $affectedSet
     */
    private function applyUnknownSourceDirs(array $unknown, array &$affectedSet): void
    {
        if ($unknown === []) {
            return;
        }

        $unknownDirs = [];

        foreach ($unknown as $rel) {
            $unknownDirs[dirname($rel)] = true;
        }

        foreach ($this->edges as $testFile => $ids) {
            if (isset($affectedSet[$testFile])) {
                continue;
            }

            foreach ($ids as $id) {
                if (! isset($this->files[$id])) {
                    continue;
                }

                if (isset($unknownDirs[dirname($this->files[$id])])) {
                    $affectedSet[$testFile] = true;

                    break;
                }
            }
        }
    }

    /**
     * Extension point (§4.3): every change core couldn't map to a known
     * source edge is offered to each registered Resolver, regardless of
     * whether the generic sibling-directory fallback already found
     * something for it — a framework package's domain knowledge (e.g. a
     * migration→table→test mapping) is more precise than a directory guess.
     *
     * @param  list<string>  $unknown
     * @param  array<string, true>  $affectedSet
     */
    private function applyResolvers(array $unknown, array &$affectedSet): void
    {
        if ($unknown === [] || $this->resolvers === []) {
            return;
        }

        foreach ($unknown as $rel) {
            foreach ($this->resolvers as $resolver) {
                foreach ($resolver->resolve($this->projectRoot, $rel) as $testFile) {
                    $testRel = $this->relative($testFile);

                    if ($testRel !== null) {
                        $affectedSet[$testRel] = true;
                    }
                }
            }
        }
    }

    public function recordedAtSha(string $branch, string $fallbackBranch = 'main'): ?string
    {
        return $this->baselineFor($branch, $fallbackBranch)['sha'];
    }

    public function setRecordedAtSha(string $branch, ?string $sha): void
    {
        $this->ensureBaseline($branch);
        $this->baselines[$branch]['sha'] = $sha;
    }

    public function setResult(string $branch, string $testId, int $status, string $message, float $time, int $assertions = 0, ?string $file = null): void
    {
        $this->ensureBaseline($branch);

        $entry = [
            'status' => $status,
            'message' => $message,
            'time' => $time,
            'assertions' => $assertions,
        ];

        if ($file !== null) {
            $rel = $this->relative($file);

            if ($rel !== null) {
                $entry['file'] = $rel;
            }
        }

        $this->baselines[$branch]['results'][$testId] = $entry;
    }

    public function getAssertions(string $branch, string $testId, string $fallbackBranch = 'main'): ?int
    {
        $baseline = $this->baselineFor($branch, $fallbackBranch);

        return $baseline['results'][$testId]['assertions'] ?? null;
    }

    public function getResult(string $branch, string $testId, string $fallbackBranch = 'main'): ?TestStatus
    {
        $baseline = $this->baselineFor($branch, $fallbackBranch);

        if (! isset($baseline['results'][$testId])) {
            return null;
        }

        $r = $baseline['results'][$testId];

        return match ($r['status']) {
            0 => TestStatus::success(),
            1 => TestStatus::skipped($r['message']),
            2 => TestStatus::incomplete($r['message']),
            3 => TestStatus::notice($r['message']),
            4 => TestStatus::deprecation($r['message']),
            5 => TestStatus::risky($r['message']),
            6 => TestStatus::warning($r['message']),
            7 => TestStatus::failure($r['message']),
            8 => TestStatus::error($r['message']),
            default => TestStatus::unknown(),
        };
    }

    /**
     * @return array<int, string>
     */
    public function testFilesToRerun(string $branch, string $fallbackBranch = 'main'): array
    {
        $baseline = $this->baselineFor($branch, $fallbackBranch);
        $files = [];

        foreach ($baseline['results'] as $result) {
            if (! $this->shouldRerun($result['status'])) {
                continue;
            }

            $file = $result['file'] ?? null;

            if ($file === null || $file === '') {
                continue;
            }

            $rel = $this->relative($file);

            if ($rel !== null) {
                $files[$rel] = true;
            }
        }

        return array_keys($files);
    }

    public function hasUnlocatedTestsToRerun(string $branch, string $fallbackBranch = 'main'): bool
    {
        $baseline = $this->baselineFor($branch, $fallbackBranch);

        foreach ($baseline['results'] as $result) {
            if (! $this->shouldRerun($result['status'])) {
                continue;
            }

            $file = $result['file'] ?? null;

            if ($file === null || $file === '' || $this->relative($file) === null) {
                return true;
            }
        }

        return false;
    }

    private function shouldRerun(int $status): bool
    {
        return $this->shouldRerunStatus(TestStatus::from($status));
    }

    /**
     * Whether a cached result with this status must be re-executed rather
     * than replayed, honouring the configured failOn* / displayDetailsOn*
     * policies — so the trait doesn't skip a test a strict CI config would
     * want re-run.
     */
    public function shouldRerunStatus(TestStatus $testStatus): bool
    {
        if ($testStatus->isFailure() || $testStatus->isError()) {
            return true;
        }

        $configuration = Registry::get();

        if ($testStatus->isRisky()) {
            return $configuration->failOnRisky();
        }

        if ($testStatus->isWarning()) {
            if ($configuration->failOnWarning()) {
                return true;
            }

            return $configuration->displayDetailsOnTestsThatTriggerWarnings();
        }

        if ($testStatus->isNotice()) {
            if ($configuration->failOnNotice()) {
                return true;
            }

            return $configuration->displayDetailsOnTestsThatTriggerNotices();
        }

        if ($testStatus->isDeprecation()) {
            if ($configuration->failOnDeprecation()) {
                return true;
            }

            return $configuration->displayDetailsOnTestsThatTriggerDeprecations();
        }

        if ($testStatus->isIncomplete()) {
            if ($configuration->failOnIncomplete()) {
                return true;
            }

            return $configuration->displayDetailsOnIncompleteTests();
        }

        if ($testStatus->isSkipped()) {
            if ($configuration->failOnSkipped()) {
                return true;
            }

            return $configuration->displayDetailsOnSkippedTests();
        }

        return false;
    }

    /**
     * @param  array<string, string>  $tree  project-relative path → content hash
     */
    public function setLastRunTree(string $branch, array $tree): void
    {
        $this->ensureBaseline($branch);
        $this->baselines[$branch]['tree'] = $tree;
    }

    public function clearResults(string $branch): void
    {
        $this->ensureBaseline($branch);
        $this->baselines[$branch]['results'] = [];
    }

    /**
     * @return array<string, string>
     */
    public function lastRunTree(string $branch, string $fallbackBranch = 'main'): array
    {
        return $this->baselineFor($branch, $fallbackBranch)['tree'];
    }

    /**
     * @return array{sha: ?string, tree: array<string, string>, results: array<string, array{status: int, message: string, time: float, assertions?: int, file?: string}>}
     */
    private function baselineFor(string $branch, string $fallbackBranch): array
    {
        if (isset($this->baselines[$branch])) {
            return $this->baselines[$branch];
        }

        if ($branch !== $fallbackBranch && isset($this->baselines[$fallbackBranch])) {
            return $this->baselines[$fallbackBranch];
        }

        return ['sha' => null, 'tree' => [], 'results' => []];
    }

    private function ensureBaseline(string $branch): void
    {
        if (! isset($this->baselines[$branch])) {
            $this->baselines[$branch] = ['sha' => null, 'tree' => [], 'results' => []];
        }
    }

    public function pruneMissingTests(): void
    {
        $root = rtrim($this->projectRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        foreach (array_keys($this->edges) as $testRel) {
            if (! is_file($root.$testRel)) {
                unset($this->edges[$testRel]);
            }
        }
    }

    /**
     * Prune baseline result entries whose test files were just executed but
     * whose test IDs no longer exist in the codebase (e.g. the test method was
     * removed or renamed).
     *
     * "No longer exists" is verified by reflection rather than inferred from
     * `$keepTestIds`. Absence from that list means "did not report a result
     * this run", which is emphatically not the same thing as "was deleted"
     * once the run is partial — and TIA's entire purpose is to make runs
     * partial:
     *
     *  - A test skipped by {@see Traits\RunWithTia} never reports at all.
     *    PHPUnit emits `Test\Prepared` *after* `setUp()`
     *    (`TestCase::runBare()`), and `setUp()` is the only hook the trait can
     *    skip from, so `ResultCollector` never sees the test. Its file is
     *    still "touched" by whichever siblings did run.
     *  - A `--filter`ed run reports only the selected methods, while their
     *    file counts as touched.
     *
     * In both cases the old heuristic deleted the cached pass of a live test,
     * so the next run had to execute it again — which recorded a pass, which
     * got skipped, which got pruned. Left a suite with zero changes
     * oscillating between mostly-skipped and mostly-re-run on alternate runs,
     * and made the inner `--filter` loop quietly destructive.
     *
     * @param  array<int, string>  $touchedFiles  Absolute or project-relative paths.
     * @param  array<int, string>  $keepTestIds  Test IDs that produced a result this run.
     */
    public function pruneStaleResults(string $branch, array $touchedFiles, array $keepTestIds): void
    {
        if (! isset($this->baselines[$branch]['results'])) {
            return;
        }

        $touched = [];

        foreach ($touchedFiles as $file) {
            $rel = $this->relative($file);

            if ($rel !== null) {
                $touched[$rel] = true;
            }
        }

        if ($touched === []) {
            return;
        }

        $keep = array_fill_keys($keepTestIds, true);

        foreach ($this->baselines[$branch]['results'] as $testId => $result) {
            $file = $result['file'] ?? null;

            if (! is_string($file) || ! isset($touched[$file]) || isset($keep[$testId])) {
                continue;
            }

            if (self::testIsStillDefined($testId)) {
                continue;
            }

            unset($this->baselines[$branch]['results'][$testId]);
        }
    }

    /**
     * Is `$testId` still a real method on a real class?
     *
     * IDs look like `Fully\Qualified\ClassName::methodName`, with a
     * `#<dataSetName>` suffix for data-provided tests (`TestMethod::id()`).
     * A class name cannot contain `::` and a method name cannot contain `#`,
     * so the first occurrence of each is the correct split point even when a
     * data-set name contains either character.
     *
     * An ID we cannot parse, or whose class cannot be resolved, is reported as
     * gone — that is the pre-existing behaviour for a deleted test file, and
     * keeping unresolvable entries forever would grow the baseline without
     * bound.
     */
    private static function testIsStillDefined(string $testId): bool
    {
        $separator = strpos($testId, '::');

        if ($separator === false) {
            return false;
        }

        $class = substr($testId, 0, $separator);
        $method = substr($testId, $separator + 2);
        $dataSet = strpos($method, '#');

        if ($dataSet !== false) {
            $method = substr($method, 0, $dataSet);
        }

        if (! class_exists($class)) {
            return false;
        }

        return method_exists($class, $method);
    }

    public static function decode(string $json, string $projectRoot): ?self
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }

        $graph = new self($projectRoot);
        $graph->fingerprint = is_array($data['fingerprint'] ?? null) ? $data['fingerprint'] : [];
        $graph->files = is_array($data['files'] ?? null) ? array_values($data['files']) : [];
        $graph->fileIds = array_flip($graph->files);
        $graph->edges = is_array($data['edges'] ?? null) ? $data['edges'] : [];
        $graph->baselines = is_array($data['baselines'] ?? null) ? $data['baselines'] : [];

        return $graph;
    }

    public function encode(): ?string
    {
        $payload = [
            'schema' => self::SCHEMA_VERSION,
            'fingerprint' => $this->fingerprint,
            'files' => $this->files,
            'edges' => $this->edges,
            'baselines' => $this->baselines,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    private function relative(string $path): ?string
    {
        if ($path === '' || $path === 'unknown') {
            return null;
        }

        if (str_contains($path, "eval()'d")) {
            return null;
        }

        $root = rtrim($this->projectRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            || (strlen($path) >= 2 && $path[1] === ':');

        if ($isAbsolute) {
            if (array_key_exists($path, $this->realpathCache)) {
                $real = $this->realpathCache[$path];
            } else {
                $real = $this->realpathCache[$path] = @realpath($path);
            }

            if ($real === false) {
                $real = $path;
            }

            if (! str_starts_with($real, $root)) {
                return null;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root)));
        } else {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $path);

            while (str_starts_with($relative, './')) {
                $relative = substr($relative, 2);
            }
        }

        if (str_starts_with($relative, 'vendor/')) {
            return null;
        }

        return $relative;
    }
}
