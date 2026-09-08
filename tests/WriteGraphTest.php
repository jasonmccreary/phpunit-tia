<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ChangedFiles;
use JMac\Testing\PhpUnit\Tia\FileState;
use JMac\Testing\PhpUnit\Tia\Fingerprint;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\ResultCollector;
use JMac\Testing\PhpUnit\Tia\RunScope;
use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Subscribers\WriteGraph;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\TextUI\CliArguments\Builder as CliBuilder;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use ReflectionClass;
use ReflectionProperty;

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
        $this->assertNotSame([], $graph->lastRunTree('main'), 'A full run must snapshot the tree.');
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

    /**
     * A run narrowed by `--filter`/`--group`/`--testsuite`/an explicit path only
     * tells us about the files its own tests touched — advancing the baseline sha
     * or re-snapshotting the tree here would "bank" whatever changed in the
     * meantime as already-seen, even though nothing verified it. The test that
     * *did* run must still be recorded, though.
     *
     * @param  list<string>  $cliArguments
     */
    #[Test]
    #[DataProvider('narrowingCliArguments')]
    public function it_leaves_the_baseline_alone_on_a_narrowed_run(array $cliArguments): void
    {
        [$seededSha, $testId] = $this->seedNarrowableBaseline(
            self::class.'::it_leaves_the_baseline_alone_on_a_narrowed_run',
        );

        $this->notify($this->resultsFor($testId), cliArguments: $cliArguments);

        $this->assertBaselineWasLeftAlone($seededSha, $testId);
    }

    /**
     * `fromParameters()` mirrors real `$argv`, where index 0 is the invoked
     * program itself, not the first argument — `SebastianBergmann\CliParser`
     * unconditionally shifts off whatever doesn't start with `-` in that slot.
     * A leading `'phpunit'` keeps a bare path argument from being swallowed as
     * if it were that program name.
     *
     * @return array<string, array{list<string>}>
     */
    public static function narrowingCliArguments(): array
    {
        return [
            '--filter' => [['phpunit', '--filter=Foo']],
            '--group' => [['phpunit', '--group=slow']],
            '--testsuite' => [['phpunit', '--testsuite=unit']],
            'explicit path' => [['phpunit', 'tests/FooTest.php']],
        ];
    }

    /**
     * Mirrors the narrowed-run case above, but for a run PHPUnit cut short itself
     * (`--stop-on-failure`/etc., or Ctrl-C) rather than one narrowed by CLI flags —
     * RunScope is how Subscribers\RecordExecutionAborted reports that.
     */
    #[Test]
    public function it_leaves_the_baseline_alone_on_an_aborted_run(): void
    {
        [$seededSha, $testId] = $this->seedNarrowableBaseline(
            self::class.'::it_leaves_the_baseline_alone_on_an_aborted_run',
        );

        $scope = new RunScope;
        $scope->abort();

        $this->notify($this->resultsFor($testId), $scope);

        $this->assertBaselineWasLeftAlone($seededSha, $testId);
    }

    /**
     * A full, unnarrowed run is the only kind that's authoritative for "every
     * dependent of a deleted source has already been re-run" — see
     * WriteGraph::notify()'s doc comment on why pruneMissingSources() shares
     * the isPartialRun() gate with the baseline advancement above it.
     */
    #[Test]
    public function it_prunes_a_deleted_source_file_on_a_full_run(): void
    {
        $testId = self::class.'::it_prunes_a_deleted_source_file_on_a_full_run';
        $this->seedGraphWithDeletableSource($testId);

        $this->notify($this->resultsFor($testId));

        $this->assertNotContains('src/Gone.php', $this->persistedGraph()->allSourceFiles());
    }

    /**
     * On a narrowed run, a test that solely depended on the deleted file may
     * not have executed yet — pruning here would strand it on the
     * sibling-directory fallback (or miss it outright) once a later full run
     * tries to resolve the deletion.
     *
     * @param  list<string>  $cliArguments
     */
    #[Test]
    #[DataProvider('narrowingCliArguments')]
    public function it_leaves_a_deleted_source_file_alone_on_a_narrowed_run(array $cliArguments): void
    {
        $testId = self::class.'::it_leaves_a_deleted_source_file_alone_on_a_narrowed_run';
        $this->seedGraphWithDeletableSource($testId);

        $this->notify($this->resultsFor($testId), cliArguments: $cliArguments);

        $this->assertContains('src/Gone.php', $this->persistedGraph()->allSourceFiles());
    }

    private function seedGraphWithDeletableSource(string $testId): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->write('src/Gone.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");
        $this->repo->commit('seed');

        $graph = new Graph($this->repo->path());
        $graph->link('tests/FooTest.php', 'src/Gone.php');
        $graph->setFingerprint(Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', (new ChangedFiles($this->repo->path()))->currentSha());

        $this->state()->write(Storage::GRAPH_KEY, (string) $graph->encode());

        unlink($this->repo->path().'/src/Gone.php');
    }

    /**
     * Seeds a baseline, stamps it with a recorded sha and a tree snapshot, then
     * commits an unrelated edit after that — the point a full run's baseline
     * would legitimately move past, and a narrowed/aborted run's must not.
     *
     * @return array{0: ?string, 1: string} the seeded sha and the test ID to report this run
     */
    private function seedNarrowableBaseline(string $testId): array
    {
        $this->seedBaselineWith([]);
        $seededSha = (new ChangedFiles($this->repo->path()))->currentSha();

        $graph = $this->persistedGraph();
        $graph->setLastRunTree('main', ['src/Foo.php' => 'stale-hash']);
        $this->state()->write(Storage::GRAPH_KEY, (string) $graph->encode());

        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n    public function bar(): void {}\n}\n");
        $this->repo->commit('unrelated edit');

        return [$seededSha, $testId];
    }

    private function assertBaselineWasLeftAlone(?string $seededSha, string $testId): void
    {
        $graph = $this->persistedGraph();

        $this->assertSame($seededSha, $graph->recordedAtSha('main'), 'The baseline sha must not advance.');
        $this->assertSame(['src/Foo.php' => 'stale-hash'], $graph->lastRunTree('main'), 'The tree must not be re-snapshotted.');
        $this->assertNotNull($graph->getResult('main', $testId), 'The test that actually ran must still be recorded.');
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
    /**
     * `WriteGraph::isPartialRun()` reads the process-wide `Registry::get()`
     * Configuration, so leaving it at whatever the *outer* `phpunit` invocation
     * running this test suite happened to use (e.g. someone's IDE running this
     * one test via `--filter`) would make these tests pass or fail depending on
     * how they're invoked. Pin it to a known, unnarrowed Configuration by
     * default — same swap-and-restore approach GraphTest.php already uses —
     * and let callers opt into a narrowed one via $cliArguments.
     *
     * @param  list<string>  $cliArguments  Passed through fromParameters(); a leading
     *                                      'phpunit' avoids the parser swallowing a lone
     *                                      positional argument as the program name.
     */
    private function notify(ResultCollector $results, ?RunScope $scope = null, array $cliArguments = ['phpunit']): void
    {
        $event = (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();

        $registry = new ReflectionProperty(Registry::class, 'instance');
        $original = $registry->getValue();

        try {
            Registry::init((new CliBuilder)->fromParameters($cliArguments), DefaultConfiguration::create());

            (new WriteGraph($this->repo->path(), $results, 'local', $scope ?? new RunScope))->notify($event);
        } finally {
            $registry->setValue(null, $original);
        }
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
