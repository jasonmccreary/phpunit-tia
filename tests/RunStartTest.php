<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ContentHash;
use JMac\Testing\PhpUnit\Tia\FileState;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\RunStart;
use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;

final class RunStartTest extends TestCase
{
    private TempGitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = TempGitRepository::create();
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");
        $this->repo->commit('init');
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            $this->repo->cleanup();
        }

        parent::tearDown();
    }

    #[Test]
    public function it_captures_the_branch_and_commit_the_run_started_on(): void
    {
        $start = RunStart::capture($this->repo->path(), 'local');

        $this->assertNotNull($start);
        $this->assertSame('main', $start->branch);
        $this->assertSame($this->repo->sha(), $start->sha);
    }

    #[Test]
    public function it_snapshots_the_files_of_the_stored_graph(): void
    {
        $this->repo->write('tests/FooTest.php', "<?php\n\nclass FooTest\n{\n}\n");
        $this->repo->commit('add FooTest');
        $this->storeGraphOf('src/Foo.php', 'tests/FooTest.php');

        $start = RunStart::capture($this->repo->path(), 'local');

        $this->assertNotNull($start);
        $this->assertSame(
            ContentHash::of($this->repo->path().'/src/Foo.php'),
            $start->tree['src/Foo.php'],
        );
        $this->assertArrayHasKey('tests/FooTest.php', $start->tree);
    }

    #[Test]
    public function it_snapshots_files_changed_in_the_working_tree_at_that_moment(): void
    {
        $this->repo->write('src/Bar.php', "<?php\n\nclass Bar\n{\n}\n");

        $start = RunStart::capture($this->repo->path(), 'local');

        $this->assertNotNull($start);
        $this->assertSame(
            ContentHash::of($this->repo->path().'/src/Bar.php'),
            $start->tree['src/Bar.php'],
        );
    }

    #[Test]
    public function it_has_nothing_to_snapshot_without_a_stored_graph_or_changes(): void
    {
        $start = RunStart::capture($this->repo->path(), 'local');

        $this->assertNotNull($start);
        $this->assertSame([], $start->tree);
    }

    private function storeGraphOf(string $source, string $test): void
    {
        $graph = new Graph($this->repo->path());
        $graph->replaceEdges([$this->repo->path().'/'.$test => [$this->repo->path().'/'.$source]]);

        (new FileState(Storage::resolve($this->repo->path(), 'local')))->write(Storage::GRAPH_KEY, (string) $graph->encode());
    }
}
