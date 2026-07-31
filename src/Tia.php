<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use PHPUnit\Framework\TestStatus\TestStatus;
use ReflectionClass;
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

    private static bool $configured = false;

    private static ?self $instance = null;

    /** @var array<string, true> project-relative test file => affected */
    private array $affectedTestFiles;

    private function __construct(
        private readonly bool $active,
        private readonly ?Graph $graph,
        private readonly string $branch,
        array $affectedTestFiles,
    ) {
        $this->affectedTestFiles = $affectedTestFiles;
    }

    public static function configure(string $projectRoot, string $storageMode = 'global'): void
    {
        self::$projectRoot = $projectRoot;
        self::$storageMode = $storageMode;
        self::$configured = true;
        self::$instance = null;
    }

    /** Test seam: drop back to the unconfigured state between test cases that touch this singleton. */
    public static function reset(): void
    {
        self::$projectRoot = null;
        self::$storageMode = 'global';
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

        try {
            $file = (new ReflectionClass($class))->getFileName();
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

    private static function boot(): self
    {
        if (! self::$configured || self::$projectRoot === null || ! self::isEnabled() || self::isFresh()) {
            return self::inactive();
        }

        try {
            return self::attemptBoot(self::$projectRoot, self::$storageMode);
        } catch (Throwable) {
            // A TIA replay failure must never break the underlying test
            // suite — fall back to letting every test actually run.
            return self::inactive();
        }
    }

    private static function attemptBoot(string $projectRoot, string $storageMode): self
    {
        $state = new FileState(Storage::resolve($projectRoot, $storageMode));
        $raw = $state->read(Storage::GRAPH_KEY);

        if ($raw === null) {
            return self::inactive();
        }

        $graph = Graph::decode($raw, $projectRoot);

        if ($graph === null) {
            return self::inactive();
        }

        $current = Fingerprint::compute($projectRoot);

        if (! Fingerprint::structuralMatches($graph->fingerprint(), $current)) {
            return self::inactive();
        }

        if (Fingerprint::environmentalDrift($graph->fingerprint(), $current) !== []) {
            return self::inactive();
        }

        $changedFiles = new ChangedFiles($projectRoot);
        $branch = $changedFiles->currentBranch() ?? 'default';
        $changed = $changedFiles->since($graph->recordedAtSha($branch));

        if ($changed === null) {
            // Baseline sha isn't reachable from HEAD (rebase, force-push) —
            // the diff can't be trusted, so don't replay anything this run.
            return self::inactive();
        }

        $changed = $changedFiles->filterUnchangedSinceLastRun($changed, $graph->lastRunTree($branch));

        return new self(true, $graph, $branch, array_fill_keys($graph->affected($changed), true));
    }

    private static function inactive(): self
    {
        return new self(false, null, 'default', []);
    }
}
