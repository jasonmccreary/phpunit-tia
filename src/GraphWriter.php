<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use JMac\Testing\PhpUnit\Tia\Contracts\State;

/**
 * Folds one run's edges and results into the stored graph. The single-process
 * run calls it directly; under ParaTest the parent calls it once, with the
 * merged shards of every worker.
 */
final readonly class GraphWriter
{
    /**
     * @param  RunStart|null  $start  where the code stood when the run started; null reads the
     *                                baseline as the run ends, for callers that took no snapshot
     */
    public function __construct(
        private string $projectRoot,
        private string $storageMode,
        private ?RunStart $start = null,
    ) {}

    /**
     * @param  array<string, list<string>>  $edges  test file (absolute) → source files (absolute), see Recorder::invert()
     * @param  array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>  $results
     * @param  bool  $authoritative  whether this run covered the whole configured suite and finished it
     */
    public function write(array $edges, array $results, bool $authoritative): void
    {
        $state = new FileState(Storage::resolve($this->projectRoot, $this->storageMode));
        $currentFingerprint = Fingerprint::compute($this->projectRoot);

        $changedFiles = new ChangedFiles($this->projectRoot);
        $branch = $this->start?->branch ?? $changedFiles->currentBranch() ?? 'default';

        $graph = $this->loadGraph($state, $currentFingerprint, $branch);

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
        // touched, not about every other file this snapshot would otherwise cover;
        // advancing the baseline here would "bank" unverified edits as already-seen
        // (see the doc comment on WriteGraph::isPartialRun()). Leave both pointers at
        // whatever the last authoritative run left them.
        //
        // pruneMissingSources() belongs in this same branch: its safety argument
        // ("the run that first saw the deletion already re-ran the dependents")
        // only holds when this run covered the whole tracked file set. On a
        // filtered/aborted run, a test that solely depended on the now-deleted
        // file may not have executed yet; pruning its edge here, before an
        // authoritative run confirms the deletion, would strand that dependent
        // on the sibling-directory fallback instead of the direct edge, and it
        // could be missed entirely if nothing else in that directory is covered.
        //
        // Both pointers come from the start of the run when it took a snapshot
        // (RunStart): its tests ran against that code, so a commit or an edit
        // made while it ran is still a change for the next run.
        if ($authoritative) {
            $graph->pruneMissingSources();

            $graph->setRecordedAtSha($branch, $this->start !== null ? $this->start->sha : $changedFiles->currentSha());
            $graph->setLastRunTree($branch, $this->start?->tree ?? $changedFiles->snapshotTree(
                array_values(array_unique([...$graph->allTestFiles(), ...$graph->allSourceFiles()])),
            ));
        }

        $encoded = $graph->encode();

        if ($encoded !== null) {
            $state->write(Storage::GRAPH_KEY, $encoded);
        }
    }

    /**
     * @param  array<string, mixed>  $currentFingerprint
     */
    private function loadGraph(State $state, array $currentFingerprint, string $branch): Graph
    {
        if (Tia::isFresh()) {
            // PHPUNIT_TIA_FRESH=1 (§7); ignore whatever's on disk and
            // rebuild the graph from this run alone.
            return new Graph($this->projectRoot);
        }

        $existing = $state->read(Storage::GRAPH_KEY);

        if ($existing === null) {
            return new Graph($this->projectRoot);
        }

        $graph = Graph::decode($existing, $this->projectRoot);

        if ($graph === null) {
            // Stale/corrupt schema from a previous package version; start fresh.
            return new Graph($this->projectRoot);
        }

        if (! Fingerprint::structuralMatches($graph->fingerprint(), $currentFingerprint)) {
            // composer.lock/phpunit.xml drifted; autoloaded classes may
            // have moved, discard edges and baselines entirely.
            return new Graph($this->projectRoot);
        }

        if (Fingerprint::environmentalDrift($graph->fingerprint(), $currentFingerprint) !== []) {
            // e.g. PHP version changed; edges are probably still valid but
            // a previously-passing result might not replay honestly.
            $graph->clearResults($branch);
        }

        return $graph;
    }
}
