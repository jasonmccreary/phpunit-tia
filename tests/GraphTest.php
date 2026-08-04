<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\TestPaths;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\TextUI\CliArguments\Builder as CliBuilder;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use ReflectionProperty;

final class GraphTest extends TestCase
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

        $this->repo->cleanup();
    }

    private function graph(): Graph
    {
        $graph = new Graph($this->repo->path());
        $graph->setTestPaths(new TestPaths(directories: ['tests'], files: [], suffixes: ['Test.php']));

        return $graph;
    }

    #[Test]
    public function link_creates_an_edge_and_knows_the_test(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link($this->repo->path().'/tests/FooTest.php', $this->repo->path().'/src/Foo.php');

        $this->assertTrue($graph->knowsTest($this->repo->path().'/tests/FooTest.php'));
        $this->assertSame(['tests/FooTest.php'], $graph->allTestFiles());
        $this->assertSame(['src/Foo.php'], $graph->allSourceFiles());
    }

    #[Test]
    public function affected_finds_tests_via_a_direct_edge(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->write('src/Bar.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");
        $this->repo->write('tests/BarTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link('tests/FooTest.php', 'src/Foo.php');
        $graph->link('tests/BarTest.php', 'src/Bar.php');

        $this->assertSame(['tests/FooTest.php'], $graph->affected(['src/Foo.php']));
    }

    #[Test]
    public function affected_treats_a_changed_test_file_as_its_own_unit_of_work(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();

        $this->assertSame(['tests/FooTest.php'], $graph->affected(['tests/FooTest.php']));
    }

    #[Test]
    public function affected_applies_the_sibling_directory_fallback_for_unknown_php(): void
    {
        // app/Listeners/Foo.php has an edge; app/Listeners/Bar.php is new
        // and has never been covered — the sibling heuristic should still
        // mark whatever test covers Foo.php as affected.
        $this->repo->write('app/Listeners/Foo.php', "<?php\n");
        $this->repo->write('app/Listeners/Bar.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link('tests/FooTest.php', 'app/Listeners/Foo.php');

        $this->assertSame(['tests/FooTest.php'], $graph->affected(['app/Listeners/Bar.php']));
    }

    #[Test]
    public function affected_offers_unmapped_changes_to_registered_resolvers(): void
    {
        $this->repo->write('database/migrations/2024_01_01_create_widgets_table.php', "<?php\n");
        $this->repo->write('tests/WidgetTest.php', "<?php\n");

        $resolver = new class implements Resolver
        {
            public function resolve(string $projectRoot, string $changedRelativePath): array
            {
                if ($changedRelativePath === 'database/migrations/2024_01_01_create_widgets_table.php') {
                    return ['tests/WidgetTest.php'];
                }

                return [];
            }
        };

        $graph = $this->graph();
        $graph->setResolvers([$resolver]);

        $this->assertSame(
            ['tests/WidgetTest.php'],
            $graph->affected(['database/migrations/2024_01_01_create_widgets_table.php']),
        );
    }

    #[Test]
    public function affected_ignores_deleted_files_with_no_edge(): void
    {
        $graph = $this->graph();

        $this->assertSame([], $graph->affected(['src/LongGoneNeverCovered.php']));
    }

    #[Test]
    public function set_result_and_get_result_round_trip(): void
    {
        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\FooTest::it_works', 0, '', 0.05, 3, 'tests/FooTest.php');

        $status = $graph->getResult('main', 'Tests\\FooTest::it_works');

        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
        $this->assertSame(3, $graph->getAssertions('main', 'Tests\\FooTest::it_works'));
    }

    #[Test]
    public function get_result_falls_back_to_the_fallback_branch(): void
    {
        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\FooTest::it_works', 0, '', 0.0);

        $status = $graph->getResult('feature/x', 'Tests\\FooTest::it_works', fallbackBranch: 'main');

        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
    }

    #[Test]
    public function get_result_is_null_for_an_unknown_test(): void
    {
        $graph = $this->graph();

        $this->assertNull($graph->getResult('main', 'Tests\\Nope::nope'));
    }

    #[Test]
    public function recorded_at_sha_round_trips(): void
    {
        $graph = $this->graph();
        $graph->setRecordedAtSha('main', 'abc123');

        $this->assertSame('abc123', $graph->recordedAtSha('main'));
    }

    #[Test]
    public function last_run_tree_round_trips(): void
    {
        $graph = $this->graph();
        $graph->setLastRunTree('main', ['src/Foo.php' => 'hash']);

        $this->assertSame(['src/Foo.php' => 'hash'], $graph->lastRunTree('main'));
    }

    #[Test]
    public function clear_results_empties_the_branch_baseline(): void
    {
        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\FooTest::it_works', 0, '', 0.0);
        $graph->clearResults('main');

        $this->assertNull($graph->getResult('main', 'Tests\\FooTest::it_works'));
    }

    #[Test]
    public function should_rerun_status_always_reruns_failures_and_errors(): void
    {
        $graph = $this->graph();

        $this->assertTrue($graph->shouldRerunStatus(TestStatus::failure('boom')));
        $this->assertTrue($graph->shouldRerunStatus(TestStatus::error('boom')));
    }

    #[Test]
    public function should_rerun_status_never_reruns_a_plain_success(): void
    {
        $graph = $this->graph();

        $this->assertFalse($graph->shouldRerunStatus(TestStatus::success()));
    }

    #[Test]
    public function test_files_to_rerun_includes_only_statuses_that_must_rerun(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");
        $this->repo->write('tests/BarTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\FooTest::it_works', 0, '', 0.0, file: 'tests/FooTest.php');
        $graph->setResult('main', 'Tests\\BarTest::it_fails', 7, 'boom', 0.0, file: 'tests/BarTest.php');

        $this->assertSame(['tests/BarTest.php'], $graph->testFilesToRerun('main'));
    }

    #[Test]
    public function has_unlocated_tests_to_rerun_is_true_when_the_file_is_unresolvable(): void
    {
        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\GoneTest::it_fails', 7, 'boom', 0.0, file: null);

        $this->assertTrue($graph->hasUnlocatedTestsToRerun('main'));
    }

    #[Test]
    public function prune_missing_tests_drops_edges_for_deleted_test_files(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");

        $graph = $this->graph();
        $graph->link('tests/GoneTest.php', 'src/Foo.php');

        $this->assertTrue($graph->knowsTest('tests/GoneTest.php'));

        $graph->pruneMissingTests();

        $this->assertFalse($graph->knowsTest('tests/GoneTest.php'));
    }

    #[Test]
    public function prune_stale_results_drops_results_for_removed_test_methods(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\FooTest::old_method', 0, '', 0.0, file: 'tests/FooTest.php');

        // This run touched FooTest.php and only produced a result for
        // it_still_here — old_method must have been removed/renamed.
        $graph->pruneStaleResults('main', ['tests/FooTest.php'], ['Tests\\FooTest::it_still_here']);

        $this->assertNull($graph->getResult('main', 'Tests\\FooTest::old_method'));
    }

    /**
     * The regression this guards against is TIA defeating itself.
     *
     * PHPUnit emits `Test\Prepared` *after* `setUp()` (`TestCase::runBare()`),
     * and `setUp()` is the only hook `RunWithTia` can skip from — so a test
     * skipped by TIA never reports a result and is absent from
     * `$keepTestIds`. Its file still counts as touched, because the siblings
     * TIA did *not* skip ran normally. Treating "touched file + unseen ID" as
     * "the method was removed" therefore deleted the cached pass of a live
     * test, and the next run had to execute it again — which recorded a pass,
     * which got skipped, which got pruned. A suite with zero changes
     * oscillated between mostly-skipped and mostly-re-run forever.
     */
    #[Test]
    public function prune_stale_results_keeps_a_still_defined_test_that_reported_nothing_this_run(): void
    {
        $this->repo->write('tests/GraphTest.php', "<?php\n");

        $graph = $this->graph();
        $skippedByTia = self::class.'::prune_stale_results_keeps_a_still_defined_test_that_reported_nothing_this_run';
        $graph->setResult('main', $skippedByTia, 0, '', 0.0, 3, file: 'tests/GraphTest.php');

        // Only a sibling reported this run; the ID above is still a real
        // method on a real class, so its result must survive.
        $graph->pruneStaleResults('main', ['tests/GraphTest.php'], [self::class.'::a_sibling_that_ran']);

        $this->assertNotNull($graph->getResult('main', $skippedByTia));
        $this->assertSame(3, $graph->getAssertions('main', $skippedByTia));
    }

    /**
     * Same guarantee for data-provided tests, whose IDs carry a
     * `#<dataSetName>` suffix that is not part of the method name.
     */
    #[Test]
    public function prune_stale_results_keeps_a_still_defined_data_provided_test(): void
    {
        $this->repo->write('tests/GraphTest.php', "<?php\n");

        $graph = $this->graph();
        $id = self::class.'::prune_stale_results_keeps_a_still_defined_data_provided_test#Spanish';
        $graph->setResult('main', $id, 0, '', 0.0, 1, file: 'tests/GraphTest.php');

        $graph->pruneStaleResults('main', ['tests/GraphTest.php'], [self::class.'::a_sibling_that_ran']);

        $this->assertNotNull($graph->getResult('main', $id));
    }

    /**
     * The original intent still holds: a method that really is gone from a
     * class that still exists must be dropped.
     */
    #[Test]
    public function prune_stale_results_drops_a_removed_method_of_an_existing_class(): void
    {
        $this->repo->write('tests/GraphTest.php', "<?php\n");

        $graph = $this->graph();
        $id = self::class.'::a_method_that_was_renamed_away';
        $graph->setResult('main', $id, 0, '', 0.0, file: 'tests/GraphTest.php');

        $graph->pruneStaleResults('main', ['tests/GraphTest.php'], [self::class.'::a_sibling_that_ran']);

        $this->assertNull($graph->getResult('main', $id));
    }

    /**
     * A malformed ID carries no class to verify against, so it is treated as
     * gone rather than kept forever.
     */
    #[Test]
    public function prune_stale_results_drops_an_unparseable_test_id(): void
    {
        $this->repo->write('tests/GraphTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->setResult('main', 'not-a-test-id', 0, '', 0.0, file: 'tests/GraphTest.php');

        $graph->pruneStaleResults('main', ['tests/GraphTest.php'], [self::class.'::a_sibling_that_ran']);

        $this->assertNull($graph->getResult('main', 'not-a-test-id'));
    }

    #[Test]
    public function encode_and_decode_round_trip(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link('tests/FooTest.php', 'src/Foo.php');
        $graph->setResult('main', 'Tests\\FooTest::it_works', 0, '', 0.01, 1, 'tests/FooTest.php');
        $graph->setFingerprint(['structural' => ['schema' => 1], 'environmental' => []]);

        $json = $graph->encode();
        $this->assertNotNull($json);

        $decoded = Graph::decode($json, $this->repo->path());
        $this->assertNotNull($decoded);
        $this->assertTrue($decoded->knowsTest('tests/FooTest.php'));
        $this->assertSame(['src/Foo.php'], $decoded->allSourceFiles());

        $status = $decoded->getResult('main', 'Tests\\FooTest::it_works');
        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
    }

    #[Test]
    public function decode_returns_null_for_the_wrong_schema_version(): void
    {
        $json = json_encode(['schema' => 999, 'files' => [], 'edges' => []]);

        $this->assertNull(Graph::decode($json, $this->repo->path()));
    }

    #[Test]
    public function decode_returns_null_for_invalid_json(): void
    {
        $this->assertNull(Graph::decode('not json', $this->repo->path()));
    }

    /**
     * `relative()` is the gate every path crosses before it can become an
     * edge, an edge key, or a result's file, so each of its rejections decides
     * whether a whole class of path silently drops out of the graph. Driven
     * through `link()`, which returns early when either side is unresolvable.
     *
     * @param  string  $path  A path `relative()` must refuse
     */
    #[Test]
    #[DataProvider('unresolvablePaths')]
    public function link_ignores_paths_relative_cannot_resolve(string $path): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link('tests/FooTest.php', $path);

        $this->assertSame([], $graph->allSourceFiles());
    }

    /** @return array<string, array{string}> */
    public static function unresolvablePaths(): array
    {
        return [
            'empty' => [''],
            // PHPUnit reports code with no file of its own as "unknown".
            'unknown' => ['unknown'],
            // eval()'d code carries a synthetic path no diff can ever match.
            "eval()'d code" => ["/some/file.php(12) : eval()'d code"],
            // A dependency's own source is not this project's unit of work.
            'inside vendor' => ['vendor/acme/pkg/src/Thing.php'],
            'absolute outside the project root' => [DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'hosts'],
        ];
    }

    /**
     * A `./`-prefixed path is the same file as its bare form, so both must
     * collapse to one entry rather than two.
     */
    #[Test]
    public function link_strips_leading_dot_slash_segments(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link('tests/FooTest.php', './src/Foo.php');
        $graph->link('tests/FooTest.php', 'src/Foo.php');

        $this->assertSame(['src/Foo.php'], $graph->allSourceFiles());
    }

    #[Test]
    public function mark_known_test_files_ignores_an_unresolvable_path(): void
    {
        $graph = $this->graph();
        $graph->markKnownTestFiles(['', 'vendor/acme/pkg/tests/ThingTest.php']);

        $this->assertSame([], $graph->allTestFiles());
    }

    #[Test]
    public function replace_edges_ignores_an_unresolvable_test_file(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");

        $graph = $this->graph();
        $graph->replaceEdges(['' => ['src/Foo.php']]);

        $this->assertSame([], $graph->allTestFiles());
    }

    /**
     * The name is the contract: the incoming set replaces that test file's
     * edges rather than adding to them, and duplicates within one payload
     * collapse.
     */
    #[Test]
    public function replace_edges_overwrites_the_previous_edge_set_for_a_test_file(): void
    {
        // Separate directories on purpose: the sibling-directory fallback
        // would otherwise bridge the dropped edge and mask the overwrite.
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->write('lib/Bar.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link('tests/FooTest.php', 'src/Foo.php');

        $graph->replaceEdges(['tests/FooTest.php' => ['lib/Bar.php', 'lib/Bar.php']]);

        $this->assertSame(['tests/FooTest.php'], $graph->allTestFiles());
        $this->assertSame(['tests/FooTest.php'], $graph->affected(['lib/Bar.php']));
        $this->assertSame([], $graph->affected(['src/Foo.php']));
    }

    /**
     * A changed test file is marked affected by the test-file rule before the
     * edge sweep runs, so the sweep must leave it alone instead of doing the
     * work twice.
     */
    #[Test]
    public function affected_does_not_revisit_a_test_file_already_marked(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link('tests/FooTest.php', 'src/Foo.php');

        $this->assertSame(
            ['tests/FooTest.php'],
            $graph->affected(['tests/FooTest.php', 'src/Foo.php']),
        );
    }

    /**
     * A test file listed twice in one diff must be marked once.
     */
    #[Test]
    public function affected_deduplicates_a_repeated_test_file(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();

        $this->assertSame(
            ['tests/FooTest.php'],
            $graph->affected(['tests/FooTest.php', 'tests/FooTest.php']),
        );
    }

    /**
     * A deleted test file is not a unit of work — there is nothing left to
     * run — so it must not be reported as affected.
     */
    #[Test]
    public function affected_ignores_a_test_file_that_no_longer_exists_on_disk(): void
    {
        $graph = $this->graph();

        $this->assertSame([], $graph->affected(['tests/DeletedTest.php']));
    }

    /**
     * A decoded graph can carry an edge pointing at a file id the `files` map
     * no longer holds (an older writer, a hand-edited graph). The sibling
     * fallback must step over it rather than resolve a null path.
     */
    #[Test]
    public function affected_skips_an_edge_whose_file_id_is_dangling(): void
    {
        $this->repo->write('app/Listeners/Known.php', "<?php\n");
        $this->repo->write('app/Listeners/Fresh.php', "<?php\n");
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $seed = $this->graph();
        $seed->link('tests/FooTest.php', 'app/Listeners/Known.php');

        $payload = json_decode((string) $seed->encode(), true);
        // Point the edge at an id that is absent from the files map.
        $payload['edges']['tests/FooTest.php'] = [9999];
        $payload['files'] = [];

        $graph = Graph::decode((string) json_encode($payload), $this->repo->path());
        $this->assertNotNull($graph);
        $graph->setTestPaths(new TestPaths(directories: ['tests'], files: [], suffixes: ['Test.php']));

        // Fresh.php has no edge, so the sibling-directory fallback runs and
        // walks the dangling id.
        $this->assertSame([], $graph->affected(['app/Listeners/Fresh.php']));
    }

    /**
     * Each non-failure status consults the run's configuration to decide
     * whether replaying it is honest. The flags themselves come from the CLI /
     * XML config, so this pins the dispatch, not the verdict.
     *
     * @param  TestStatus  $status  The status whose policy branch must be reached
     */
    #[Test]
    #[DataProvider('policyStatuses')]
    public function should_rerun_status_consults_the_configuration_for_each_status(TestStatus $status): void
    {
        $graph = $this->graph();

        $this->assertIsBool($graph->shouldRerunStatus($status));
    }

    /** @return array<string, array{TestStatus}> */
    public static function policyStatuses(): array
    {
        return [
            'risky' => [TestStatus::risky('')],
            'warning' => [TestStatus::warning('')],
            'notice' => [TestStatus::notice('')],
            'deprecation' => [TestStatus::deprecation('')],
            'incomplete' => [TestStatus::incomplete('')],
            'skipped' => [TestStatus::skipped('')],
        ];
    }

    /**
     * Under `--fail-on-*`, replaying a cached non-success status would hide a
     * result the run has been told to treat as red, so every one of those
     * statuses must be re-run.
     *
     * The flags live on the run's `Configuration`, which is a process-wide
     * singleton owned by PHPUnit, so this swaps it for one built from those
     * CLI parameters and puts the original back in a `finally`.
     *
     * @param  TestStatus  $status  A non-success status that `--fail-on-*` covers
     */
    #[Test]
    #[DataProvider('policyStatuses')]
    public function should_rerun_status_reruns_every_status_the_run_fails_on(TestStatus $status): void
    {
        $graph = $this->graph();

        $registry = new ReflectionProperty(Registry::class, 'instance');
        $original = $registry->getValue();

        try {
            Registry::init(
                (new CliBuilder)->fromParameters([
                    '--fail-on-risky',
                    '--fail-on-warning',
                    '--fail-on-notice',
                    '--fail-on-deprecation',
                    '--fail-on-incomplete',
                    '--fail-on-skipped',
                ]),
                DefaultConfiguration::create(),
            );

            $this->assertTrue($graph->shouldRerunStatus($status));
        } finally {
            $registry->setValue(null, $original);
        }
    }

    /**
     * An absolute path that does not exist cannot be realpath'd, so the raw
     * path is used for the inside-the-project check — and rejected.
     */
    #[Test]
    public function link_ignores_an_absolute_path_that_cannot_be_resolved(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->link(
            'tests/FooTest.php',
            DIRECTORY_SEPARATOR.'no'.DIRECTORY_SEPARATOR.'such'.DIRECTORY_SEPARATOR.'file.php',
        );

        $this->assertSame([], $graph->allSourceFiles());
    }

    /**
     * Every stored status integer must map back to the matching TestStatus.
     * The write side persists `TestStatus::asInt()`, so a wrong arm here
     * replays a test as the wrong outcome — and `cachedStatusIfUnaffected()`
     * decides whether to skip purely on `isSuccess()`.
     *
     * @param  int  $stored  The integer persisted in the baseline
     * @param  string  $predicate  The TestStatus predicate that must hold on the way back
     */
    #[Test]
    #[DataProvider('storedStatuses')]
    public function get_result_maps_every_stored_status_back(int $stored, string $predicate): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\FooTest::it_works', $stored, 'stored message', 0.0, 0, 'tests/FooTest.php');

        $status = $graph->getResult('main', 'Tests\\FooTest::it_works');

        $this->assertNotNull($status);
        $this->assertTrue($status->{$predicate}(), "Stored status {$stored} should satisfy {$predicate}()");
    }

    /** @return array<string, array{int, string}> */
    public static function storedStatuses(): array
    {
        return [
            'success' => [0, 'isSuccess'],
            'skipped' => [1, 'isSkipped'],
            'incomplete' => [2, 'isIncomplete'],
            'notice' => [3, 'isNotice'],
            'deprecation' => [4, 'isDeprecation'],
            'risky' => [5, 'isRisky'],
            'warning' => [6, 'isWarning'],
            'failure' => [7, 'isFailure'],
            'error' => [8, 'isError'],
            'unrecognised' => [99, 'isUnknown'],
        ];
    }

    #[Test]
    public function prune_stale_results_is_a_no_op_without_a_baseline_for_the_branch(): void
    {
        $graph = $this->graph();

        $graph->pruneStaleResults('a-branch-never-recorded', ['tests/FooTest.php'], []);

        $this->assertNull($graph->getResult('a-branch-never-recorded', 'Tests\\FooTest::it_works'));
    }

    /**
     * No resolvable touched file means nothing can be judged stale, so the
     * baseline must be left alone rather than emptied.
     */
    #[Test]
    public function prune_stale_results_is_a_no_op_when_no_touched_file_resolves(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\FooTest::gone', 0, '', 0.0, 0, 'tests/FooTest.php');

        $graph->pruneStaleResults('main', ['vendor/acme/pkg/tests/ThingTest.php'], []);

        $this->assertNotNull($graph->getResult('main', 'Tests\\FooTest::gone'));
    }

    /**
     * A must-rerun result with no usable file contributes nothing to the
     * rerun list, and a passing one is filtered out before the file is even
     * looked at.
     */
    #[Test]
    public function test_files_to_rerun_skips_results_with_no_usable_file(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        // Failure (7) → must re-run, but there is no file to attribute it to.
        $graph->setResult('main', 'Tests\\FooTest::no_file', 7, 'boom', 0.0);
        $graph->setResult('main', 'Tests\\FooTest::empty_file', 7, 'boom', 0.0, 0, '');
        // Success → nothing to re-run; exercises the status filter.
        $graph->setResult('main', 'Tests\\FooTest::passed', 0, '', 0.0, 0, 'tests/FooTest.php');

        $this->assertSame([], $graph->testFilesToRerun('main'));
    }

    #[Test]
    public function has_unlocated_tests_to_rerun_ignores_a_passing_result(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n");

        $graph = $this->graph();
        $graph->setResult('main', 'Tests\\FooTest::passed', 0, '', 0.0, 0, 'tests/FooTest.php');

        $this->assertFalse($graph->hasUnlocatedTestsToRerun('main'));
    }
}
