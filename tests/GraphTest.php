<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\TestPaths;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestStatus\TestStatus;

final class GraphTest extends TestCase
{
    private TempGitRepository $repo;

    protected function setUp(): void
    {
        $this->repo = TempGitRepository::create();
    }

    protected function tearDown(): void
    {
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
}
