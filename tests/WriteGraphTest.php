<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ChangedFiles;
use JMac\Testing\PhpUnit\Tia\FileState;
use JMac\Testing\PhpUnit\Tia\Fingerprint;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\ResultCollector;
use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Subscribers\WriteGraph;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestStatus\TestStatus;
use ReflectionClass;

/**
 * Covers the write side end to end: load the on-disk graph, fold in this
 * run's results, prune, persist.
 *
 * The interesting case is a *partial* run, which is what TIA produces by
 * design — either because it skipped the unaffected tests itself or because
 * the developer passed `--filter`. Under a partial run the baseline must keep
 * the results of tests that did not report, or the next run has nothing to
 * replay and TIA undoes its own work.
 */
final class WriteGraphTest extends TestCase
{
    private TempGitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = TempGitRepository::create();
    }

    protected function tearDown(): void
    {
        if ($this->skippedByTia()) {
            return;
        }

        if (isset($this->repo)) {
            $this->repo->cleanup();
        }

        parent::tearDown();
    }

    #[Test]
    public function it_keeps_the_baseline_result_of_a_test_that_did_not_report_this_run(): void
    {
        $reported = self::class.'::it_keeps_the_baseline_result_of_a_test_that_did_not_report_this_run';
        $silent = self::class.'::it_persists_a_fresh_result_and_stamps_the_baseline';

        $this->seedBaselineWith([$reported, $silent]);

        // Only `$reported` produced a result — `$silent` is the test TIA
        // skipped (or `--filter` excluded), so it never reaches
        // ResultCollector even though its file counts as executed.
        $results = new ResultCollector;
        $results->testPrepared($reported, $this->repo->path().'/tests/FooTest.php');
        $results->testPassed();

        $this->notify($results);

        $graph = $this->persistedGraph();

        $this->assertNotNull($graph->getResult('main', $reported));
        $this->assertNotNull(
            $graph->getResult('main', $silent),
            'A test that reported nothing this run must keep its cached pass — otherwise TIA re-runs it next time.',
        );
    }

    #[Test]
    public function it_persists_a_fresh_result_and_stamps_the_baseline(): void
    {
        $testId = self::class.'::it_persists_a_fresh_result_and_stamps_the_baseline';

        $this->seedBaselineWith([]);

        $results = new ResultCollector;
        $results->testPrepared($testId, $this->repo->path().'/tests/FooTest.php');
        $results->testPassed();
        $results->recordAssertions($testId, 4);

        $this->notify($results);

        $graph = $this->persistedGraph();
        $status = $graph->getResult('main', $testId);

        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
        $this->assertSame(4, $graph->getAssertions('main', $testId));
        $this->assertNotNull($graph->recordedAtSha('main'));
    }

    /**
     * The original pruning intent, at this level: a baseline entry whose
     * method really is gone must not survive a run that executed its file.
     */
    #[Test]
    public function it_drops_the_baseline_result_of_a_test_that_no_longer_exists(): void
    {
        $reported = self::class.'::it_drops_the_baseline_result_of_a_test_that_no_longer_exists';
        $removed = self::class.'::a_method_deleted_in_a_previous_refactor';

        $this->seedBaselineWith([$reported, $removed]);

        $results = new ResultCollector;
        $results->testPrepared($reported, $this->repo->path().'/tests/FooTest.php');
        $results->testPassed();

        $this->notify($results);

        $graph = $this->persistedGraph();

        $this->assertNotNull($graph->getResult('main', $reported));
        $this->assertNull($graph->getResult('main', $removed));
    }

    #[Test]
    public function it_rebuilds_from_this_run_alone_when_fresh_is_requested(): void
    {
        $stale = self::class.'::a_result_from_a_previous_run';
        $this->seedBaselineWith([$stale]);

        putenv('PHPUNIT_TIA_FRESH=1');

        try {
            $this->notify($this->resultsFor(self::class.'::it_rebuilds_from_this_run_alone_when_fresh_is_requested'));
        } finally {
            putenv('PHPUNIT_TIA_FRESH');
        }

        $this->assertNull($this->persistedGraph()->getResult('main', $stale));
    }

    #[Test]
    public function it_starts_a_new_graph_when_nothing_is_stored_yet(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");
        $this->repo->commit('seed without a graph');

        $testId = self::class.'::it_starts_a_new_graph_when_nothing_is_stored_yet';

        $this->notify($this->resultsFor($testId));

        $this->assertNotNull($this->persistedGraph()->getResult('main', $testId));
    }

    #[Test]
    public function it_starts_a_new_graph_when_the_stored_one_cannot_be_decoded(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");
        $this->repo->commit('seed');
        $this->state()->write(Storage::GRAPH_KEY, 'not json at all');

        $testId = self::class.'::it_starts_a_new_graph_when_the_stored_one_cannot_be_decoded';

        $this->notify($this->resultsFor($testId));

        $this->assertNotNull($this->persistedGraph()->getResult('main', $testId));
    }

    /**
     * composer.lock / phpunit.xml drift means autoloaded classes may have
     * moved, so edges and baselines are discarded wholesale.
     */
    #[Test]
    public function it_discards_the_baseline_when_the_structural_fingerprint_drifted(): void
    {
        $stale = self::class.'::a_result_recorded_under_a_different_lockfile';
        $this->seedBaselineWith([$stale], fingerprint: [
            'structural' => ['schema' => -1],
            'environmental' => ['php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION],
        ]);

        $this->notify($this->resultsFor(self::class.'::it_discards_the_baseline_when_the_structural_fingerprint_drifted'));

        $this->assertNull($this->persistedGraph()->getResult('main', $stale));
    }

    /**
     * A PHP version change leaves the edges plausible but makes a recorded
     * pass unsafe to replay, so only the results for that branch are cleared.
     */
    #[Test]
    public function it_clears_only_the_results_when_the_environmental_fingerprint_drifted(): void
    {
        $stale = self::class.'::a_result_recorded_under_a_different_php_version';

        $fingerprint = Fingerprint::compute($this->repo->path());
        $fingerprint['environmental']['php_version'] = '1.0';

        $this->seedBaselineWith([$stale], fingerprint: $fingerprint);

        $this->notify($this->resultsFor(self::class.'::it_clears_only_the_results_when_the_environmental_fingerprint_drifted'));

        $this->assertNull($this->persistedGraph()->getResult('main', $stale));
    }

    private function resultsFor(string $testId): ResultCollector
    {
        $results = new ResultCollector;
        $results->testPrepared($testId, $this->repo->path().'/tests/FooTest.php');
        $results->testPassed();

        return $results;
    }

    /**
     * @param  array<int, string>  $testIds
     * @param  array<string, mixed>|null  $fingerprint  Defaults to the repo's real fingerprint.
     */
    private function seedBaselineWith(array $testIds, ?array $fingerprint = null): void
    {
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");
        $this->repo->commit('seed');

        $graph = new Graph($this->repo->path());

        foreach ($testIds as $testId) {
            $graph->setResult('main', $testId, TestStatus::success()->asInt(), '', 0.01, 1, 'tests/FooTest.php');
        }

        $graph->setFingerprint($fingerprint ?? Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', (new ChangedFiles($this->repo->path()))->currentSha());

        $this->state()->write(Storage::GRAPH_KEY, (string) $graph->encode());
    }

    /**
     * `WriteGraph::notify()` never reads its `ExecutionFinished` argument — it
     * only needs the event to satisfy the subscriber signature. Building a
     * real one would mean assembling PHPUnit's whole telemetry value-object
     * tree (Snapshot, Duration, MemoryUsage, GarbageCollectorStatus) for a
     * value that is then discarded, so instantiate it without its constructor
     * instead.
     */
    private function notify(ResultCollector $results): void
    {
        $event = (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();

        (new WriteGraph($this->repo->path(), $results, 'local'))->notify($event);
    }

    private function persistedGraph(): Graph
    {
        $raw = $this->state()->read(Storage::GRAPH_KEY);

        $this->assertNotNull($raw, 'WriteGraph should have persisted a graph.');

        $graph = Graph::decode($raw, $this->repo->path());

        $this->assertNotNull($graph, 'The persisted graph should decode.');

        return $graph;
    }

    private function state(): FileState
    {
        return new FileState(Storage::resolve($this->repo->path(), 'local'));
    }
}
