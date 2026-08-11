<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests\Cli;

use JMac\Testing\PhpUnit\Tia\ChangedFiles;
use JMac\Testing\PhpUnit\Tia\Cli\Selection;
use JMac\Testing\PhpUnit\Tia\FileState;
use JMac\Testing\PhpUnit\Tia\Fingerprint;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use JMac\Testing\PhpUnit\Tia\Tia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestStatus\TestStatus;

/**
 * Drives the decision table against a real graph in a scratch repo — no
 * doubles, since the whole point of these cases is that the real impact
 * analysis feeds the real selection.
 */
final class SelectionTest extends TestCase
{
    private TempGitRepository $repo;

    protected function setUp(): void
    {
        Tia::reset();

        $this->repo = TempGitRepository::create();
    }

    protected function tearDown(): void
    {
        Tia::reset();

        // The rest of the suite dogfoods TIA through this singleton; re-arm it
        // exactly as Extension::bootstrap() would, as TiaTest does.
        Tia::configure(dirname(__DIR__, 2), 'global');

        $this->repo->cleanup();
    }

    #[Test]
    public function it_runs_everything_when_there_is_no_usable_graph(): void
    {
        Tia::configure($this->repo->path(), 'local');

        $plan = (new Selection(Tia::instance()))->plan(['tests/FooTest.php'], []);

        $this->assertTrue($plan->isRunAll());
    }

    #[Test]
    public function it_runs_nothing_when_the_graph_is_usable_and_nothing_changed(): void
    {
        $this->recordGraph();

        Tia::configure($this->repo->path(), 'local');

        $plan = (new Selection(Tia::instance()))->plan(['tests/FooTest.php'], []);

        $this->assertTrue($plan->isRunNothing());
    }

    #[Test]
    public function it_selects_the_test_covering_a_changed_source_file(): void
    {
        $this->recordGraph();
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n    public function added(): void {}\n}\n");

        Tia::configure($this->repo->path(), 'local');

        $plan = (new Selection(Tia::instance()))->plan(['tests/FooTest.php'], []);

        $this->assertSame(['tests/FooTest.php'], $plan->paths());
    }

    /**
     * A test file the graph has never recorded is in neither the affected set
     * nor the re-run set, because both are driven by what changed. Selecting
     * by path would drop it silently and it could stay unrun forever.
     */
    #[Test]
    public function it_always_selects_a_test_file_the_graph_does_not_know(): void
    {
        $this->recordGraph();

        Tia::configure($this->repo->path(), 'local');

        $plan = (new Selection(Tia::instance()))->plan(['tests/FooTest.php', 'tests/BrandNewTest.php'], []);

        $this->assertSame(['tests/BrandNewTest.php'], $plan->paths());
    }

    /**
     * Positional paths bypass <exclude>, so anything the configured suite
     * would not run must never be emitted.
     */
    #[Test]
    public function it_never_selects_a_file_the_suite_would_not_run(): void
    {
        $this->recordGraph();
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n    public function added(): void {}\n}\n");

        Tia::configure($this->repo->path(), 'local');

        $plan = (new Selection(Tia::instance()))->plan([], []);

        $this->assertTrue($plan->isRunNothing());
    }

    #[Test]
    public function it_intersects_with_the_paths_the_user_asked_for(): void
    {
        $this->recordGraph();
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n    public function added(): void {}\n}\n");

        Tia::configure($this->repo->path(), 'local');

        $plan = (new Selection(Tia::instance()))->plan(['tests/FooTest.php'], ['tests/Feature']);

        $this->assertTrue($plan->isRunNothing());
    }

    /**
     * Records a graph where tests/FooTest.php covers src/Foo.php, committed so
     * the baseline sha is reachable.
     */
    private function recordGraph(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");
        $this->repo->write('tests/FooTest.php', "<?php\n\nclass FooTest\n{\n}\n");

        $sha = $this->repo->commit('add Foo + FooTest');

        $graph = new Graph($this->repo->path());
        $graph->link($this->repo->path().'/tests/FooTest.php', $this->repo->path().'/src/Foo.php');
        $graph->setResult('main', 'FooTest::test_it_works', TestStatus::success()->asInt(), '', 0.01, 1, 'tests/FooTest.php');
        $graph->setFingerprint(Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', $sha);

        $changedFiles = new ChangedFiles($this->repo->path());
        $graph->setLastRunTree('main', $changedFiles->snapshotTree(['src/Foo.php', 'tests/FooTest.php']));

        (new FileState(Storage::resolve($this->repo->path(), 'local')))
            ->write(Storage::GRAPH_KEY, (string) $graph->encode());
    }
}
