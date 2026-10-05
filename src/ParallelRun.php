<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use PHPUnit\TextUI\Configuration\Registry;
use Throwable;

/**
 * Lets ParaTest record a graph (issue #14). Every worker is a separate
 * PHPUnit process, so writing the shared graph from each one loses most of
 * it: the workers read the same graph, add their own tests, and the last
 * write wins. A lock would not help under PHPUNIT_TIA_FRESH=1, where every
 * worker starts from an empty graph anyway.
 *
 * Instead each worker writes a shard of its own, and the ParaTest parent,
 * which bootstraps the extensions too and outlives every worker, merges them
 * in a single write. The baseline only advances when every worker finished
 * and none stopped early, the same rule WriteGraph::isPartialRun() applies
 * to a single process.
 */
final readonly class ParallelRun
{
    public const string ENV = 'PHPUNIT_TIA_PARALLEL_RUN';

    private const string KEY_PREFIX = 'parallel-';

    /**
     * Shards are only left behind when the parent itself was killed, and
     * nothing merges them after that, so they are swept up after a day.
     */
    private const int STALE_AFTER_SECONDS = 86400;

    private function __construct(
        private FileState $state,
        private string $runId,
        private string $workerId = '',
    ) {}

    /**
     * ParaTest sets this in every worker's environment (brianium/paratest
     * src/Options.php), regardless of --no-test-tokens or any other flag.
     */
    public static function isWorker(): bool
    {
        return getenv('PARATEST') !== false;
    }

    /**
     * ParaTest's parent bootstraps extensions from its SuiteLoader,
     * a class a plain PHPUnit process never loads.
     */
    public static function isParent(): bool
    {
        return ! self::isWorker() && class_exists('ParaTest\\WrapperRunner\\SuiteLoader', false);
    }

    /**
     * Parent side: starts the run and merges the workers' shards once ParaTest
     * is done with them, which is the last thing the parent process does.
     */
    public static function coordinate(string $projectRoot, string $storageMode): void
    {
        $run = self::start($projectRoot, $storageMode);

        register_shutdown_function(static function () use ($run, $projectRoot, $storageMode): void {
            $notice = $run->merge(new GraphWriter($projectRoot, $storageMode));

            if ($notice !== null) {
                fwrite(STDERR, $notice);
            }
        });
    }

    /**
     * Hands the workers a run id through the environment they inherit. A
     * PHPUnit process a test spawns inherits the id as well, so the id ends in
     * its project, and only a worker of that same project joins the run.
     */
    public static function start(string $projectRoot, string $storageMode): self
    {
        $runId = bin2hex(random_bytes(8)).self::projectHash($projectRoot);

        // Symfony Process builds a child's environment from $_SERVER and $_ENV, not getenv() alone.
        putenv(self::ENV.'='.$runId);
        $_ENV[self::ENV] = $runId;
        $_SERVER[self::ENV] = $runId;

        return new self(new FileState(Storage::resolve($projectRoot, $storageMode)), $runId);
    }

    /**
     * Worker side. The pending marker stands in for the shard until the worker
     * finishes, so the parent can't mistake a crashed worker for a done one.
     */
    public static function join(string $projectRoot, string $storageMode): ?self
    {
        $runId = getenv(self::ENV);

        if (! is_string($runId)
            || preg_match('/^[0-9a-f]{24}$/', $runId) !== 1
            || ! str_ends_with($runId, self::projectHash($projectRoot))) {
            return null;
        }

        $run = new self(
            new FileState(Storage::resolve($projectRoot, $storageMode)),
            $runId,
            bin2hex(random_bytes(4)),
        );

        return $run->state->write($run->key('pending'), '') ? $run : null;
    }

    /**
     * @param  array<string, list<string>>  $edges  see Recorder::invert()
     * @param  array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>  $results
     * @param  bool  $aborted  this worker stopped mid-suite (RunScope)
     * @param  bool  $successful  ParaTest stops handing out tests on a failed worker when a failure threshold is set
     */
    public function writeShard(array $edges, array $results, bool $aborted, bool $successful): void
    {
        $encoded = json_encode([
            'edges' => $edges,
            'results' => $results,
            'aborted' => $aborted,
            'successful' => $successful,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // A parent that sees both files still counts this worker as unfinished.
        if ($encoded !== false && $this->state->write($this->key('shard'), $encoded)) {
            $this->state->delete($this->key('pending'));
        }
    }

    /**
     * Runs in the parent's shutdown, once ParaTest has stopped every worker. A
     * pending marker without a shard means that worker crashed or was killed,
     * so its tests never reported and the baseline has to stay where it is.
     * Returns the notice to print, if any, so the shutdown hook owns STDERR.
     */
    public function merge(GraphWriter $writer): ?string
    {
        try {
            $edges = [];
            $results = [];
            $shards = 0;
            $complete = true;
            $failed = false;

            foreach ($this->state->keysWithPrefix(self::KEY_PREFIX.$this->runId.'-') as $key) {
                $shard = str_ends_with($key, '.shard') ? json_decode($this->state->read($key) ?? '', true) : null;

                $this->state->delete($key);

                // A pending marker, or a shard that can't be read back.
                if (! is_array($shard)) {
                    $complete = false;

                    continue;
                }

                $shards++;

                foreach ($shard['edges'] ?? [] as $testFile => $sources) {
                    $edges[$testFile] = [...($edges[$testFile] ?? []), ...$sources];
                }

                $results = [...$results, ...($shard['results'] ?? [])];
                $complete = $complete && ($shard['aborted'] ?? true) === false;
                $failed = $failed || ($shard['successful'] ?? false) !== true;
            }

            $this->sweepStale();

            // ParaTest stops handing out tests once any worker fails and a failure
            // threshold is set (WrapperRunner::assignAllPendingTests()), so the
            // tests it never handed out would otherwise be banked as unchanged.
            $stoppedEarly = $failed && Registry::get()->stopOnFailureThreshold() > 0;

            $notice = $complete && ! $stoppedEarly
                ? null
                : "phpunit-tia: a ParaTest worker stopped early or did not finish, baseline not advanced for this run.\n";

            if ($shards > 0) {
                $writer->write($edges, $results, $notice === null && ! SuiteSelection::isNarrowed());
            }

            return $notice;
        } catch (Throwable $e) {
            return "phpunit-tia: could not merge the ParaTest worker shards ({$e->getMessage()}), graph left unchanged.\n";
        }
    }

    private function sweepStale(): void
    {
        foreach ($this->state->keysWithPrefix(self::KEY_PREFIX) as $key) {
            $modified = @filemtime($this->state->pathFor($key));

            if ($modified !== false && $modified < time() - self::STALE_AFTER_SECONDS) {
                $this->state->delete($key);
            }
        }
    }

    private static function projectHash(string $projectRoot): string
    {
        return hash('crc32b', $projectRoot);
    }

    private function key(string $kind): string
    {
        return self::KEY_PREFIX.$this->runId.'-'.$this->workerId.'.'.$kind;
    }
}
