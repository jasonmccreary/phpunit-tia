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
use JMac\Testing\PhpUnit\Tia\Storage;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\CodeCoverage;

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
    private const string GRAPH_KEY = 'graph.json';

    public function __construct(
        private string $projectRoot,
        private ResultCollector $results,
        private string $storageMode,
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
        $edges = $coverage->isActive()
            ? Recorder::invert($coverage->codeCoverage()->getData()->lineCoverage(), $results)
            : [];
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
        $graph->setRecordedAtSha($branch, $changedFiles->currentSha());
        $graph->setLastRunTree($branch, $changedFiles->snapshotTree(
            array_values(array_unique([...$graph->allTestFiles(), ...$graph->allSourceFiles()])),
        ));

        $encoded = $graph->encode();

        if ($encoded !== null) {
            $state->write(self::GRAPH_KEY, $encoded);
        }
    }

    /**
     * @param  array<string, mixed>  $currentFingerprint
     */
    private function loadGraph(State $state, array $currentFingerprint, string $branch): Graph
    {
        $existing = $state->read(self::GRAPH_KEY);

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
