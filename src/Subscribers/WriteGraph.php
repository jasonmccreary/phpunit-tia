<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ChangedFiles;
use JMac\Testing\PhpUnit\Tia\Contracts\State;
use JMac\Testing\PhpUnit\Tia\FileState;
use JMac\Testing\PhpUnit\Tia\Fingerprint;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\Recorder;
use JMac\Testing\PhpUnit\Tia\ResultCollector;
use JMac\Testing\PhpUnit\Tia\RunScope;
use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Tia;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use Throwable;

/**
 * The one piece of Pest's Tia.php orchestration this milestone needs:
 * record-only mode. Runs once at the end of the suite, builds/updates the
 * Graph from whatever ResultCollector + Recorder captured this session, and
 * persists it. Deliberately does not decide replay-vs-run (that's
 * RunWithTia, milestone 4) — this subscriber's only job is "confirm a graph
 * gets written after a real run" (§10, milestone 3).
 */
final readonly class WriteGraph implements ExecutionFinishedSubscriber
{
    public function __construct(
        private string $projectRoot,
        private ResultCollector $results,
        private string $storageMode,
        private RunScope $scope,
    ) {}

    public function notify(ExecutionFinished $event): void
    {
        $state = new FileState(Storage::resolve($this->projectRoot, $this->storageMode));
        $currentFingerprint = Fingerprint::compute($this->projectRoot);

        $changedFiles = new ChangedFiles($this->projectRoot);
        $branch = $changedFiles->currentBranch() ?? 'default';

        $graph = $this->loadGraph($state, $currentFingerprint, $branch);

        $results = $this->results->all();

        $coverage = CodeCoverage::instance();
        $edges = [];

        if ($coverage->isActive()) {
            $data = $coverage->codeCoverage()->getData();
            $testIdByIndex = method_exists($data, 'testIds') ? $data->testIds() : [];
            $edges = Recorder::invert($data->lineCoverage(), $results, $testIdByIndex);
        }

        $graph->replaceEdges($edges);

        $executedTestFiles = [];
        $keepTestIds = [];

        foreach ($results as $testId => $result) {
            $file = $result['file'] ?? null;

            if ($file !== null) {
                $executedTestFiles[] = $file;
            }

            $graph->setResult(
                $branch,
                $testId,
                $result['status'],
                $result['message'],
                $result['time'],
                $result['assertions'],
                $file,
            );

            $keepTestIds[] = $testId;
        }

        $graph->markKnownTestFiles($executedTestFiles);
        $graph->pruneStaleResults($branch, $executedTestFiles, $keepTestIds);
        $graph->pruneMissingTests();

        $graph->setFingerprint($currentFingerprint);

        // A narrowed or aborted run only tells us about the files its own tests
        // touched, not about every other file this snapshot would otherwise cover —
        // advancing the baseline here would "bank" unverified edits as already-seen
        // (see the doc comment on isPartialRun()). Leave both pointers at whatever
        // the last authoritative run left them.
        //
        // pruneMissingSources() belongs in this same branch: its safety argument
        // ("the run that first saw the deletion already re-ran the dependents")
        // only holds when this run covered the whole tracked file set. On a
        // filtered/aborted run, a test that solely depended on the now-deleted
        // file may not have executed yet — pruning its edge here, before an
        // authoritative run confirms the deletion, would strand that dependent
        // on the sibling-directory fallback instead of the direct edge, and it
        // could be missed entirely if nothing else in that directory is covered.
        if (! $this->isPartialRun()) {
            $graph->pruneMissingSources();

            $graph->setRecordedAtSha($branch, $changedFiles->currentSha());
            $graph->setLastRunTree($branch, $changedFiles->snapshotTree(
                array_values(array_unique([...$graph->allTestFiles(), ...$graph->allSourceFiles()])),
            ));
        }

        $encoded = $graph->encode();

        if ($encoded !== null) {
            $state->write(Storage::GRAPH_KEY, $encoded);
        }
    }

    /**
     * Whether this run isn't authoritative for "every known file not touched this
     * run is unchanged" — either because it was narrowed to less than the full
     * configured suite (`--filter`, `--group`, `--testsuite`, or an explicit path
     * argument), or because it was cut short before finishing (`--stop-on-*`, or
     * Ctrl-C via RunScope — see that class's doc comment). A narrowed/aborted run
     * still executed real tests, whose results/edges are recorded as usual; only
     * the baseline pointers that claim to describe the *whole* tracked file set
     * are held back.
     *
     * The groups and default test suite selected by phpunit.xml itself are that
     * full configured suite, not a narrowing: PHPUnit merges them into the same
     * Configuration as their command-line counterparts, so they are compared to
     * what the XML file selects on its own rather than just tested for presence.
     * Otherwise a project that permanently excludes a group (slow, external...)
     * never records a baseline at all.
     */
    private function isPartialRun(): bool
    {
        if ($this->scope->wasAborted()) {
            return true;
        }

        $configuration = Registry::get();

        return $configuration->hasFilter()
            || $configuration->hasExcludeFilter()
            || $configuration->hasTestIdFilter()
            || $configuration->hasTestIdFilterFile()
            || $this->groupsDifferFromXmlConfiguration($configuration)
            || $configuration->includeTestSuites() !== $this->defaultTestSuites($configuration)
            || $configuration->excludeTestSuites() !== []
            || $configuration->hasCliArguments();
    }

    /**
     * Mirrors how PHPUnit's Merger derives the groups when the command line sets
     * none: the XML `<groups>` include list, and its exclude list minus any group
     * also included. If the XML file can't be read back, assume a narrowing — the
     * safe direction, since it only holds the baseline back.
     */
    private function groupsDifferFromXmlConfiguration(Configuration $configuration): bool
    {
        try {
            $xmlGroups = $configuration->hasConfigurationFile()
                ? (new Loader)->load($configuration->configurationFile())->groups()
                : null;
        } catch (Throwable) {
            return true;
        }

        $include = $xmlGroups === null ? [] : self::nonEmpty($xmlGroups->include()->asArrayOfStrings());
        $exclude = $xmlGroups === null ? [] : array_diff(self::nonEmpty($xmlGroups->exclude()->asArrayOfStrings()), $include);

        // groups()/excludeGroups() throw when empty rather than returning [].
        $groups = $configuration->hasGroups() ? $configuration->groups() : [];
        $excludeGroups = $configuration->hasExcludeGroups() ? $configuration->excludeGroups() : [];

        return ! self::sameSet($groups, $include) || ! self::sameSet($excludeGroups, $exclude);
    }

    /**
     * @return list<string>
     */
    private function defaultTestSuites(Configuration $configuration): array
    {
        if (! $configuration->hasDefaultTestSuite()) {
            return [];
        }

        return self::nonEmpty(explode(',', $configuration->defaultTestSuite()));
    }

    /**
     * @param  array<array-key, string>  $values
     * @return list<string>
     */
    private static function nonEmpty(array $values): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<array-key, string>  $actual
     * @param  array<array-key, string>  $expected
     */
    private static function sameSet(array $actual, array $expected): bool
    {
        $actual = array_values(array_unique($actual));
        $expected = array_values(array_unique($expected));

        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /**
     * @param  array<string, mixed>  $currentFingerprint
     */
    private function loadGraph(State $state, array $currentFingerprint, string $branch): Graph
    {
        if (Tia::isFresh()) {
            // PHPUNIT_TIA_FRESH=1 (§7) — ignore whatever's on disk and
            // rebuild the graph from this run alone.
            return new Graph($this->projectRoot);
        }

        $existing = $state->read(Storage::GRAPH_KEY);

        if ($existing === null) {
            return new Graph($this->projectRoot);
        }

        $graph = Graph::decode($existing, $this->projectRoot);

        if ($graph === null) {
            // Stale/corrupt schema from a previous package version — start fresh.
            return new Graph($this->projectRoot);
        }

        if (! Fingerprint::structuralMatches($graph->fingerprint(), $currentFingerprint)) {
            // composer.lock/phpunit.xml drifted — autoloaded classes may
            // have moved, discard edges and baselines entirely.
            return new Graph($this->projectRoot);
        }

        if (Fingerprint::environmentalDrift($graph->fingerprint(), $currentFingerprint) !== []) {
            // e.g. PHP version changed — edges are probably still valid but
            // a previously-passing result might not replay honestly.
            $graph->clearResults($branch);
        }

        return $graph;
    }
}
