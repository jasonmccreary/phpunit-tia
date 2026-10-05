<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\FileState;
use JMac\Testing\PhpUnit\Tia\Fingerprint;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\GraphWriter;
use JMac\Testing\PhpUnit\Tia\ParallelRun;
use JMac\Testing\PhpUnit\Tia\ResultCollector;
use JMac\Testing\PhpUnit\Tia\RunScope;
use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Subscribers\WriteGraph;
use JMac\Testing\PhpUnit\Tia\TestPaths;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\TextUI\CliArguments\Builder as CliBuilder;
use PHPUnit\TextUI\Configuration\Merger;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use ReflectionClass;
use ReflectionProperty;

/**
 * Covers recording under ParaTest (issue #14): workers write shards, and the
 * parent merges them into the graph in a single write.
 *
 * The interesting cases are the ones where the merged graph is *not* the whole
 * suite: a worker that crashed, stopped mid-suite, or never got its share of
 * the tests because ParaTest stopped handing them out. The results that did
 * arrive are still worth keeping, but advancing the baseline would bank the
 * missing tests as unchanged, so it has to stay where the last full run left it.
 */
final class ParallelRunTest extends TestCase
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

        putenv('PARATEST');
        putenv(ParallelRun::ENV);
        unset($_ENV[ParallelRun::ENV], $_SERVER[ParallelRun::ENV]);

        if (isset($this->repo)) {
            $this->repo->cleanup();
        }

        parent::tearDown();
    }

    #[Test]
    public function it_merges_every_worker_shard_into_one_graph_and_advances_the_baseline(): void
    {
        $seededSha = $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Foo.php'),
            $this->resultsFor('Tests\\FooTest::test_foo', 'tests/FooTest.php'),
            aborted: false,
            successful: true,
        );
        $this->joinWorker()->writeShard(
            $this->edges('tests/BarTest.php', 'src/Bar.php'),
            $this->resultsFor('Tests\\BarTest::test_bar', 'tests/BarTest.php'),
            aborted: false,
            successful: true,
        );

        $this->assertNull($this->merge($parent));

        $graph = $this->persistedGraph();

        $this->assertNotNull($graph->getResult('main', 'Tests\\FooTest::test_foo'));
        $this->assertNotNull($graph->getResult('main', 'Tests\\BarTest::test_bar'));
        $this->assertSame(['tests/FooTest.php'], $graph->affected(['src/Foo.php']));
        $this->assertSame(['tests/BarTest.php'], $graph->affected(['src/Bar.php']));
        $this->assertNotSame($seededSha, $graph->recordedAtSha('main'));
        $this->assertSame($this->repo->sha(), $graph->recordedAtSha('main'), 'A complete parallel run must advance the baseline to HEAD.');
        $this->assertSame([], $this->parallelKeys(), 'The merged shards must be removed.');
    }

    /**
     * `--functional` hands out single test methods, so one test file can be
     * split across workers, and each one only covers part of what it touches.
     */
    #[Test]
    public function it_keeps_the_edges_of_every_worker_that_ran_part_of_a_test_file(): void
    {
        $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Foo.php'),
            $this->resultsFor('Tests\\FooTest::test_foo', 'tests/FooTest.php'),
            aborted: false,
            successful: true,
        );
        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Bar.php'),
            $this->resultsFor('Tests\\FooTest::test_bar', 'tests/FooTest.php'),
            aborted: false,
            successful: true,
        );

        $this->merge($parent);

        // Not affected(): its sibling-directory fallback would still match a
        // dropped src/Foo.php edge through the src/Bar.php one next to it.
        $this->assertEqualsCanonicalizing(['src/Foo.php', 'src/Bar.php'], $this->persistedGraph()->allSourceFiles());
    }

    /**
     * A worker that crashed or was killed never replaces its pending marker
     * with a shard, so its tests never reported.
     */
    #[Test]
    public function it_holds_the_baseline_back_when_a_worker_did_not_finish(): void
    {
        $seededSha = $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Foo.php'),
            $this->resultsFor('Tests\\FooTest::test_foo', 'tests/FooTest.php'),
            aborted: false,
            successful: true,
        );
        $this->joinWorker();

        $notice = $this->merge($parent);

        $this->assertNotNull($notice);
        $this->assertStringContainsString('did not finish', $notice);
        $this->assertBaselineWasHeldBack($seededSha);
        $this->assertNotNull(
            $this->persistedGraph()->getResult('main', 'Tests\\FooTest::test_foo'),
            'The results of the workers that did finish must still be recorded.',
        );
        $this->assertSame([], $this->parallelKeys(), 'The pending marker must be removed with the shards.');
    }

    #[Test]
    public function it_holds_the_baseline_back_when_a_worker_stopped_mid_suite(): void
    {
        $seededSha = $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Foo.php'),
            $this->resultsFor('Tests\\FooTest::test_foo', 'tests/FooTest.php', TestStatus::error('boom')),
            aborted: true,
            successful: false,
        );

        $this->assertNotNull($this->merge($parent));
        $this->assertBaselineWasHeldBack($seededSha);
    }

    #[Test]
    public function it_holds_the_baseline_back_when_paratest_stopped_handing_out_tests(): void
    {
        $seededSha = $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        // The worker itself finished its file; it is ParaTest that drops the
        // tests still pending once a worker fails under --stop-on-failure.
        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Foo.php'),
            $this->resultsFor('Tests\\FooTest::test_foo', 'tests/FooTest.php', TestStatus::failure('nope')),
            aborted: false,
            successful: false,
        );

        $this->assertNotNull($this->merge($parent, ['phpunit', '--stop-on-failure']));
        $this->assertBaselineWasHeldBack($seededSha);
    }

    /**
     * Failing tests are as much a part of a full run as passing ones; without a
     * failure threshold ParaTest keeps handing out tests and every one reports.
     */
    #[Test]
    public function it_advances_the_baseline_after_a_failure_when_no_failure_threshold_is_set(): void
    {
        $seededSha = $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Foo.php'),
            $this->resultsFor('Tests\\FooTest::test_foo', 'tests/FooTest.php', TestStatus::failure('nope')),
            aborted: false,
            successful: false,
        );

        $this->assertNull($this->merge($parent));

        $graph = $this->persistedGraph();

        $this->assertNotSame($seededSha, $graph->recordedAtSha('main'));
        $this->assertSame($this->repo->sha(), $graph->recordedAtSha('main'));
    }

    #[Test]
    public function it_holds_the_baseline_back_on_a_narrowed_parallel_run(): void
    {
        $seededSha = $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Foo.php'),
            $this->resultsFor('Tests\\FooTest::test_foo', 'tests/FooTest.php'),
            aborted: false,
            successful: true,
        );

        $this->assertNull($this->merge($parent, ['phpunit', '--filter', 'test_foo']), 'A narrowed run is expected, not worth a notice.');
        $this->assertBaselineWasHeldBack($seededSha);
        $this->assertNotNull($this->persistedGraph()->getResult('main', 'Tests\\FooTest::test_foo'));
    }

    #[Test]
    public function it_leaves_the_graph_alone_when_no_worker_wrote_a_shard(): void
    {
        $this->seedBaseline();
        $before = $this->state()->read(Storage::GRAPH_KEY);
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $this->joinWorker();

        $this->assertNotNull($this->merge($parent));
        $this->assertSame($before, $this->state()->read(Storage::GRAPH_KEY));
        $this->assertSame([], $this->parallelKeys());
    }

    /**
     * Failure messages can carry arbitrary bytes from the code under test, and a
     * shard that can't be encoded would leave its pending marker standing.
     */
    #[Test]
    public function it_keeps_a_shard_whose_results_hold_invalid_utf8(): void
    {
        $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $this->joinWorker()->writeShard(
            $this->edges('tests/FooTest.php', 'src/Foo.php'),
            $this->resultsFor('Tests\\FooTest::test_foo', 'tests/FooTest.php', TestStatus::failure("expected \xB1\x31")),
            aborted: false,
            successful: false,
        );

        $this->assertNull($this->merge($parent));
        $this->assertNotNull($this->persistedGraph()->getResult('main', 'Tests\\FooTest::test_foo'));
    }

    /**
     * Files linked through Tia::link() never show up in coverage, so a worker
     * has to carry them in its shard alongside the edges coverage recorded.
     */
    #[Test]
    public function it_carries_linked_files_from_a_worker_into_the_merged_graph(): void
    {
        $this->seedBaseline();
        $this->repo->write('resources/views/foo.blade.php', "<div></div>\n");
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $results = new ResultCollector;
        $results->testPrepared('Tests\\FooTest::test_foo', $this->repo->path().'/tests/FooTest.php');
        $results->link($this->repo->path().'/resources/views/foo.blade.php');
        $results->testPassed();

        $event = (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();

        (new WriteGraph($this->repo->path(), $results, 'local', new RunScope, $this->joinWorker()))->notify($event);

        $this->assertNull($this->merge($parent));

        $graph = $this->persistedGraph();
        $graph->setTestPaths(new TestPaths(directories: ['tests'], files: [], suffixes: ['Test.php']));

        $this->assertSame(['tests/FooTest.php'], $graph->testsLinkedTo('resources/views/foo.blade.php'));
    }

    #[Test]
    public function it_does_not_join_without_a_run_id_from_the_parent(): void
    {
        $this->assertNull(ParallelRun::join($this->repo->path(), 'local'));

        putenv(ParallelRun::ENV.'=../../not-a-run-id');

        $this->assertNull(ParallelRun::join($this->repo->path(), 'local'));
    }

    /**
     * A test can spawn a PHPUnit process of its own, which inherits the run id
     * without being a worker of this run.
     */
    #[Test]
    public function it_does_not_join_a_run_started_for_another_project(): void
    {
        $other = TempGitRepository::create();

        try {
            ParallelRun::start($other->path(), 'local');

            $this->assertNull(ParallelRun::join($this->repo->path(), 'local'));
            $this->assertNotNull(ParallelRun::join($other->path(), 'local'));
        } finally {
            $other->cleanup();
        }
    }

    #[Test]
    public function it_sweeps_up_shards_a_killed_run_left_behind(): void
    {
        $this->seedBaseline();
        $parent = ParallelRun::start($this->repo->path(), 'local');

        $stale = 'parallel-'.str_repeat('a', 24).'-deadbeef.shard';
        $recent = 'parallel-'.str_repeat('b', 24).'-deadbeef.pending';

        $this->state()->write($stale, '{}');
        $this->state()->write($recent, '');
        touch($this->state()->pathFor($stale), time() - 2 * 86400);

        $this->merge($parent);

        $this->assertSame([$recent], $this->parallelKeys(), 'Only the abandoned shard is swept; a concurrent run keeps its own.');
    }

    #[Test]
    public function it_is_never_the_parent_in_a_worker_or_a_plain_phpunit_process(): void
    {
        $this->assertFalse(ParallelRun::isParent());

        putenv('PARATEST=1');

        $this->assertFalse(ParallelRun::isParent());
    }

    /**
     * Mirrors WriteGraphTest's seeding, plus a stale tree and a commit on top,
     * so an advanced baseline is told apart from one that was held back.
     */
    private function seedBaseline(): string
    {
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");
        $this->repo->write('src/Bar.php', "<?php\n\nclass Bar\n{\n}\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");
        $this->repo->write('tests/BarTest.php', "<?php\n");
        $seededSha = $this->repo->commit('seed');

        $graph = new Graph($this->repo->path());
        $graph->setFingerprint(Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', $seededSha);
        $graph->setLastRunTree('main', ['src/Foo.php' => 'stale-hash']);

        $this->state()->write(Storage::GRAPH_KEY, (string) $graph->encode());

        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n    public function bar(): void {}\n}\n");
        $this->repo->commit('unrelated edit');

        return $seededSha;
    }

    private function assertBaselineWasHeldBack(string $seededSha): void
    {
        $graph = $this->persistedGraph();

        $this->assertSame($seededSha, $graph->recordedAtSha('main'), 'The baseline sha must not advance.');
        $this->assertSame(['src/Foo.php' => 'stale-hash'], $graph->lastRunTree('main'), 'The tree must not be re-snapshotted.');
    }

    private function joinWorker(): ParallelRun
    {
        $worker = ParallelRun::join($this->repo->path(), 'local');

        $this->assertNotNull($worker, 'A worker of the same project should join the run.');

        return $worker;
    }

    /**
     * The merge reads the process-wide `Registry::get()` (narrowing, failure
     * threshold), so it gets a configuration of its own, as in WriteGraphTest.
     *
     * @param  list<string>  $cliArguments
     */
    private function merge(ParallelRun $parent, array $cliArguments = ['phpunit']): ?string
    {
        $registry = new ReflectionProperty(Registry::class, 'instance');
        $original = $registry->getValue();

        try {
            // Not Registry::init(): PHPUnit 13.4 has it emit an event, as in WriteGraphTest.
            $registry->setValue(null, (new Merger(EventFacade::emitter()))->merge(
                (new CliBuilder(EventFacade::emitter()))->fromParameters($cliArguments),
                DefaultConfiguration::create(),
            ));

            return $parent->merge(new GraphWriter($this->repo->path(), 'local'));
        } finally {
            $registry->setValue(null, $original);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function edges(string $testFile, string ...$sourceFiles): array
    {
        return [
            $this->repo->path().'/'.$testFile => array_map(
                fn (string $sourceFile): string => $this->repo->path().'/'.$sourceFile,
                $sourceFiles,
            ),
        ];
    }

    /**
     * @return array<string, array{status: int, message: string, time: float, assertions: int, file: string}>
     */
    private function resultsFor(string $testId, string $testFile, ?TestStatus $status = null): array
    {
        $status ??= TestStatus::success();

        return [$testId => [
            'status' => $status->asInt(),
            'message' => $status->message(),
            'time' => 0.01,
            'assertions' => 1,
            'file' => $this->repo->path().'/'.$testFile,
        ]];
    }

    private function persistedGraph(): Graph
    {
        $raw = $this->state()->read(Storage::GRAPH_KEY);

        $this->assertNotNull($raw, 'The merge should have persisted a graph.');

        $graph = Graph::decode($raw, $this->repo->path());

        $this->assertNotNull($graph, 'The persisted graph should decode.');

        return $graph;
    }

    /**
     * @return list<string>
     */
    private function parallelKeys(): array
    {
        return $this->state()->keysWithPrefix('parallel-');
    }

    private function state(): FileState
    {
        return new FileState(Storage::resolve($this->repo->path(), 'local'));
    }
}
