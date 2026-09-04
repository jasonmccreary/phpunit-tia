<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use PHPUnit\Framework\TestStatus\TestStatus;
use ReflectionMethod;
use Throwable;

/**
 * Process-wide facade the RunWithTia trait talks to (§4.7). Extension::bootstrap()
 * calls configure() once, before any test runs; the first RunWithTia::setUp()
 * call then lazily loads the on-disk graph and computes the affected set
 * exactly once for the whole run — every subsequent test just looks itself
 * up in that already-computed state.
 *
 * Deliberately decoupled from whether a coverage driver is available this
 * run: recording needs one, replaying an already-recorded graph doesn't, so
 * Extension::bootstrap() calls configure() unconditionally and only gates
 * subscriber registration on driver availability.
 */
final class Tia
{
    private static ?string $projectRoot = null;

    private static string $storageMode = 'global';

    private static string $fallbackBranch = Graph::DEFAULT_FALLBACK_BRANCH;

    /** @var list<Contracts\Resolver> */
    private static array $resolvers = [];

    private static bool $configured = false;

    private static ?self $instance = null;

    /** @var array<string, true> project-relative test file => affected */
    private array $affectedTestFiles;

    /** @var array<string, string> project-relative test file => why it was marked affected (§ diagnostics) */
    private array $affectedReasons;

    /**
     * @param  array<string, true>  $affectedTestFiles
     * @param  array<string, string>  $affectedReasons
     */
    private function __construct(
        private readonly bool $active,
        private readonly ?Graph $graph,
        private readonly string $branch,
        array $affectedTestFiles,
        array $affectedReasons = [],
        private readonly ?string $inactiveReason = null,
    ) {
        $this->affectedTestFiles = $affectedTestFiles;
        $this->affectedReasons = $affectedReasons;
    }

    /**
     * @param  list<Contracts\Resolver>  $resolvers  Extension point (§4.3, §8) — Extension::bootstrap()
     *                                               loads these from the optional phpunit-tia.php config
     *                                               file (Config::loadResolvers()) since PHPUnit's own
     *                                               flat-string ParameterCollection can't express a list.
     */
    public static function configure(
        string $projectRoot,
        string $storageMode = 'global',
        array $resolvers = [],
        string $fallbackBranch = Graph::DEFAULT_FALLBACK_BRANCH,
    ): void {
        self::$projectRoot = $projectRoot;
        self::$storageMode = $storageMode;
        self::$resolvers = $resolvers;
        self::$fallbackBranch = self::normalizeFallbackBranch($fallbackBranch);
        self::$configured = true;
        self::$instance = null;
    }

    /**
     * Both entry points — Extension's `fallback-branch` XML parameter and any
     * direct configure() call — pass through here, so Graph and everything
     * downstream can trust a trimmed, non-empty branch name. Normalizing in
     * Extension instead would leave direct callers unnormalized.
     */
    private static function normalizeFallbackBranch(string $fallbackBranch): string
    {
        $trimmed = trim($fallbackBranch);

        return $trimmed !== '' ? $trimmed : Graph::DEFAULT_FALLBACK_BRANCH;
    }

    /** Test seam: drop back to the unconfigured state between test cases that touch this singleton. */
    public static function reset(): void
    {
        self::$projectRoot = null;
        self::$storageMode = 'global';
        self::$fallbackBranch = Graph::DEFAULT_FALLBACK_BRANCH;
        self::$resolvers = [];
        self::$configured = false;
        self::$instance = null;
    }

    public static function instance(): self
    {
        return self::$instance ??= self::boot();
    }

    public static function isEnabled(): bool
    {
        return getenv('PHPUNIT_TIA') !== '0';
    }

    public static function isFresh(): bool
    {
        return getenv('PHPUNIT_TIA_FRESH') === '1';
    }

    /** `PHPUNIT_TIA_DEBUG=1` — have {@see Traits\RunWithTia} report why each non-skipped test ran. */
    public static function isDebug(): bool
    {
        return getenv('PHPUNIT_TIA_DEBUG') === '1';
    }

    /**
     * Only ever returns a cached **success** status — §4.7's deliberate
     * simplification vs. Pest's four-way ReplayType. A cached failure/error
     * needs a fresh stack trace, not a stale message, and risky/incomplete
     * are left to actually re-run too; only "known, unaffected, last run
     * passed" is safe to replay as a skip.
     */
    public function cachedStatusIfUnaffected(string $class, string $method): ?TestStatus
    {
        if (! $this->active || $this->graph === null) {
            return null;
        }

        // ReflectionMethod, not ReflectionClass: must match the file PHPUnit itself
        // recorded the edge under (TestMethodBuilder → Reflection::sourceLocationFor),
        // which is the method's *declaring* file. For a test inherited from an
        // abstract fixture, that's the fixture's file, not the concrete subclass's —
        // using ReflectionClass here would never find the edge Recorder wrote.
        try {
            $file = (new ReflectionMethod($class, self::methodNameOnly($method)))->getFileName();
        } catch (Throwable) {
            return null;
        }

        if ($file === false || ! $this->graph->knowsTest($file)) {
            return null;
        }

        $relative = $this->graph->relativePath($file);

        if ($relative === null || isset($this->affectedTestFiles[$relative])) {
            return null;
        }

        $status = $this->graph->getResult($this->branch, $class.'::'.$method);

        return $status !== null && $status->isSuccess() ? $status : null;
    }

    public function cachedAssertionCount(string $class, string $method): int
    {
        return $this->graph?->getAssertions($this->branch, $class.'::'.$method) ?? 0;
    }

    /**
     * Port of §3's policy check, applied to the *skip* the trait is about to
     * perform rather than to the cached status (which is always a plain
     * success — see cachedStatusIfUnaffected()). This is what lets a suite
     * running --fail-on-skipped (or displaying skip details) opt out of TIA
     * per-suite: if the current run's config would treat a skip as CI-red,
     * this returns true and the trait lets the test actually execute instead
     * of manufacturing a skip that policy doesn't want.
     */
    public function shouldRerunStatus(TestStatus $status): bool
    {
        return $this->graph?->shouldRerunStatus($status) ?? true;
    }

    public function recordedAtSha(): ?string
    {
        return $this->graph?->recordedAtSha($this->branch);
    }

    /**
     * `PHPUNIT_TIA_DEBUG=1` companion to {@see cachedStatusIfUnaffected()} —
     * called by the trait only once it's already decided *not* to skip, to
     * explain why. Mirrors that method's own early-return structure so the
     * two stay in lockstep, but returns a reason string at each branch
     * instead of `null`.
     */
    public function debugReason(string $class, string $method): string
    {
        if (! $this->active || $this->graph === null) {
            return $this->inactiveReason ?? 'TIA inactive this run';
        }

        try {
            $file = (new ReflectionMethod($class, self::methodNameOnly($method)))->getFileName();
        } catch (Throwable) {
            return 'test method could not be reflected';
        }

        if ($file === false) {
            return "test method has no resolvable file (e.g. eval()'d code)";
        }

        if (! $this->graph->knowsTest($file)) {
            return 'not yet recorded (new or never-run test)';
        }

        $relative = $this->graph->relativePath($file);

        if ($relative === null) {
            return 'test file is outside the project root';
        }

        if (isset($this->affectedTestFiles[$relative])) {
            return $this->affectedReasons[$relative] ?? 'marked affected';
        }

        $status = $this->graph->getResult($this->branch, $class.'::'.$method);

        if ($status === null) {
            return 'no cached result yet';
        }

        if (! $status->isSuccess()) {
            return "cached status was {$status->asString()}, only cached passes replay";
        }

        return "a skip would violate this run's fail-on-skipped/display-skipped (or similar) policy";
    }

    /**
     * RunWithTia passes `methodName#dataSetName` as $method to key data-provided
     * results in the graph, but that composite isn't a real declared method —
     * ReflectionMethod needs just the method name to find where it's declared.
     */
    private static function methodNameOnly(string $method): string
    {
        return explode('#', $method, 2)[0];
    }

    private static function boot(): self
    {
        if (! self::$configured || self::$projectRoot === null) {
            return self::inactive('TIA is not configured for this run');
        }

        if (! self::isEnabled()) {
            return self::inactive('disabled via PHPUNIT_TIA=0');
        }

        if (self::isFresh()) {
            return self::inactive('PHPUNIT_TIA_FRESH=1 — baseline is being rebuilt this run');
        }

        try {
            return self::attemptBoot(
                self::$projectRoot,
                self::$storageMode,
                self::$resolvers,
                self::$fallbackBranch,
            );
        } catch (Throwable) {
            // A TIA replay failure must never break the underlying test
            // suite — fall back to letting every test actually run.
            return self::inactive('an internal error occurred while loading the TIA graph');
        }
    }

    /**
     * @param  list<Contracts\Resolver>  $resolvers
     */
    private static function attemptBoot(
        string $projectRoot,
        string $storageMode,
        array $resolvers,
        string $fallbackBranch,
    ): self {
        $state = new FileState(Storage::resolve($projectRoot, $storageMode));
        $raw = $state->read(Storage::GRAPH_KEY);

        if ($raw === null) {
            return self::inactive('no stored graph yet (looks like the first run)');
        }

        $graph = Graph::decode($raw, $projectRoot);

        if ($graph === null) {
            return self::inactive('stored graph could not be decoded (corrupt, or from an unsupported schema version)');
        }

        $graph->setResolvers($resolvers);
        $graph->setFallbackBranch($fallbackBranch);

        $current = Fingerprint::compute($projectRoot);

        if (! Fingerprint::structuralMatches($graph->fingerprint(), $current)) {
            return self::inactive('composer.lock/phpunit.xml changed since the stored graph was written');
        }

        if (Fingerprint::environmentalDrift($graph->fingerprint(), $current) !== []) {
            return self::inactive('the environment (e.g. PHP version) drifted since the stored graph was written');
        }

        $changedFiles = new ChangedFiles($projectRoot);
        $branch = $changedFiles->currentBranch() ?? 'default';
        $changed = $changedFiles->since($graph->recordedAtSha($branch));

        if ($changed === null) {
            // Baseline sha isn't reachable from HEAD (rebase, force-push) —
            // the diff can't be trusted, so don't replay anything this run.
            return self::inactive('baseline commit is not reachable from HEAD (rebase/force-push?) — the diff cannot be trusted');
        }

        $changed = $changedFiles->filterUnchangedSinceLastRun($changed, $graph->lastRunTree($branch));

        $reasons = [];
        $affected = $graph->affected($changed, $reasons);

        return new self(true, $graph, $branch, array_fill_keys($affected, true), $reasons);
    }

    private static function inactive(?string $reason = null): self
    {
        return new self(false, null, 'default', [], [], $reason);
    }
}
