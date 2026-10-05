<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\GraphWriter;
use JMac\Testing\PhpUnit\Tia\ParallelRun;
use JMac\Testing\PhpUnit\Tia\Recorder;
use JMac\Testing\PhpUnit\Tia\ResultCollector;
use JMac\Testing\PhpUnit\Tia\RunScope;
use JMac\Testing\PhpUnit\Tia\SuiteSelection;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;

/**
 * The one piece of Pest's Tia.php orchestration this milestone needs:
 * record-only mode. Runs once at the end of the suite, builds/updates the
 * Graph from whatever ResultCollector + Recorder captured this session, and
 * persists it through GraphWriter. Under ParaTest each worker hands its share
 * to ParallelRun instead, and the parent persists the merged result (issue
 * #14). Deliberately does not decide replay-vs-run (that's
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
        private ?ParallelRun $parallelRun = null,
    ) {}

    public function notify(ExecutionFinished $event): void
    {
        $results = $this->results->all();

        $coverage = CodeCoverage::instance();
        $edges = [];

        if ($coverage->isActive()) {
            $data = $coverage->codeCoverage()->getData();
            $testIdByIndex = method_exists($data, 'testIds') ? $data->testIds() : [];
            $edges = Recorder::invert($data->lineCoverage(), $results, $testIdByIndex);
        }

        // Edges coverage cannot see, linked through Tia::link() while the tests ran.
        foreach ($this->results->links() as $testFile => $sourceFiles) {
            $edges[$testFile] = [...$edges[$testFile] ?? [], ...$sourceFiles];
        }

        if ($this->parallelRun !== null) {
            $this->parallelRun->writeShard($edges, $results, $this->scope->wasAborted(), TestResultFacade::result()->wasSuccessful());

            return;
        }

        (new GraphWriter($this->projectRoot, $this->storageMode))->write($edges, $results, ! $this->isPartialRun());
    }

    /**
     * Whether this run isn't authoritative for "every known file not touched this
     * run is unchanged", either because it was narrowed to less than the full
     * configured suite (SuiteSelection::isNarrowed()), or because it was cut short
     * before finishing (`--stop-on-*`, or Ctrl-C via RunScope; see that class's doc
     * comment). A narrowed/aborted run still executed real tests, whose results/edges
     * are recorded as usual; only the baseline pointers that claim to describe the
     * *whole* tracked file set are held back.
     */
    private function isPartialRun(): bool
    {
        return $this->scope->wasAborted() || SuiteSelection::isNarrowed();
    }
}
