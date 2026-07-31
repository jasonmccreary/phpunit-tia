<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ChangedFiles;
use JMac\Testing\PhpUnit\Tia\ContentHash;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;

final class ChangedFilesTest extends TestCase
{
    private TempGitRepository $repo;

    private ChangedFiles $changedFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = TempGitRepository::create();
        $this->changedFiles = new ChangedFiles($this->repo->path());
    }

    protected function tearDown(): void
    {
        if ($this->skippedByTia()) {
            return;
        }

        $this->repo->cleanup();
    }

    #[Test]
    public function current_sha_reflects_head(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n\nfinal class Foo {}\n");
        $sha = $this->repo->commit('add Foo');

        $this->assertSame($sha, $this->changedFiles->currentSha());
    }

    #[Test]
    public function current_branch_returns_the_branch_name(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->commit('add Foo');

        $this->assertSame('main', $this->changedFiles->currentBranch());
    }

    #[Test]
    public function since_null_returns_uncommitted_working_tree_changes(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->commit('add Foo');

        $this->repo->write('src/Bar.php', "<?php\n");

        $this->assertSame(['src/Bar.php'], $this->changedFiles->since(null));
    }

    #[Test]
    public function since_sha_returns_files_changed_since_that_sha_plus_working_tree(): void
    {
        $this->repo->write('src/Foo.php', "<?php\nfinal class Foo {}\n");
        $baseline = $this->repo->commit('add Foo');

        $this->repo->write('src/Foo.php', "<?php\nfinal class Foo { public function bar(): void {} }\n");
        $this->repo->commit('change Foo');

        $this->repo->write('src/Baz.php', "<?php\n");

        $changed = $this->changedFiles->since($baseline);
        sort($changed);

        $this->assertSame(['src/Baz.php', 'src/Foo.php'], $changed);
    }

    #[Test]
    public function since_an_unreachable_sha_returns_null(): void
    {
        $this->repo->write('src/Foo.php', "<?php\n");
        $this->repo->commit('add Foo');

        $this->assertNull($this->changedFiles->since(str_repeat('a', 40)));
    }

    #[Test]
    public function since_ignores_gitignored_files(): void
    {
        $this->repo->write('.gitignore', "ignored.php\n");
        $this->repo->commit('add gitignore');

        $this->repo->write('ignored.php', "<?php\n");
        $this->repo->write('tracked.php', "<?php\n");

        $this->assertSame(['tracked.php'], $this->changedFiles->since(null));
    }

    #[Test]
    public function since_sha_drops_files_that_are_behaviourally_unchanged(): void
    {
        $this->repo->write('src/Foo.php', "<?php\nfunction foo() {\n    return 1;\n}\n");
        $baseline = $this->repo->commit('add Foo');

        $this->repo->write('src/Foo.php', "<?php\nfunction foo(){return 1;}\n");
        $this->repo->commit('reformat Foo');

        $this->assertSame([], $this->changedFiles->since($baseline));
    }

    #[Test]
    public function filter_unchanged_since_last_run_excludes_untouched_files(): void
    {
        $this->repo->write('src/Foo.php', "<?php\nfinal class Foo {}\n");
        $this->repo->write('src/Bar.php', "<?php\nfinal class Bar {}\n");
        $this->repo->commit('add files');

        $lastRunTree = [
            'src/Foo.php' => ContentHash::of($this->repo->path().'/src/Foo.php'),
            'src/Bar.php' => 'stale-hash',
        ];

        $remaining = $this->changedFiles->filterUnchangedSinceLastRun(
            ['src/Foo.php', 'src/Bar.php'],
            $lastRunTree,
        );

        $this->assertSame(['src/Bar.php'], $remaining);
    }

    #[Test]
    public function since_translates_paths_when_project_root_is_a_repo_subdirectory(): void
    {
        // A monorepo-style install (or this package's own fixture-app/
        // during dogfooding): $projectRoot is a subdirectory of the git
        // repo, not its top level. git status/diff always print paths
        // relative to the repo root regardless of cwd, so without
        // translation these would come back prefixed with 'pkg/' instead of
        // matching the graph's own pkg-relative edge keys.
        $this->repo->write('outer.txt', "irrelevant\n");
        $this->repo->write('pkg/src/Foo.php', "<?php\nfinal class Foo {}\n");
        $this->repo->commit('add outer file + pkg');

        $this->repo->write('pkg/src/Bar.php', "<?php\n");
        $this->repo->write('outer.txt', "changed outside the package\n");

        $changedFiles = new ChangedFiles($this->repo->path().'/pkg');

        $this->assertSame(['src/Bar.php'], $changedFiles->since(null));
    }

    #[Test]
    public function since_sha_behavioural_diff_works_when_project_root_is_a_repo_subdirectory(): void
    {
        $this->repo->write('pkg/src/Foo.php', "<?php\nfunction foo() {\n    return 1;\n}\n");
        $baseline = $this->repo->commit('add pkg/Foo');

        // Reformat only (behaviourally unchanged) — exercises contentAtSha's
        // git show <sha>:<repo-root-relative path> lookup, which needs the
        // prefix re-applied since git object addressing ignores cwd.
        $this->repo->write('pkg/src/Foo.php', "<?php\nfunction foo(){return 1;}\n");
        $this->repo->commit('reformat pkg/Foo');

        $changedFiles = new ChangedFiles($this->repo->path().'/pkg');

        $this->assertSame([], $changedFiles->since($baseline));
    }

    #[Test]
    public function since_ignores_gitignored_files_when_project_root_is_a_repo_subdirectory(): void
    {
        $this->repo->write('pkg/.gitignore', "ignored.php\n");
        $this->repo->write('pkg/src/Foo.php', "<?php\n");
        $this->repo->commit('add pkg with gitignore');

        $this->repo->write('pkg/ignored.php', "<?php\n");
        $this->repo->write('pkg/tracked.php', "<?php\n");

        $changedFiles = new ChangedFiles($this->repo->path().'/pkg');

        $this->assertSame(['tracked.php'], $changedFiles->since(null));
    }

    #[Test]
    public function snapshot_tree_hashes_each_file(): void
    {
        $this->repo->write('src/Foo.php', "<?php\nfinal class Foo {}\n");
        $this->repo->commit('add Foo');

        $snapshot = $this->changedFiles->snapshotTree(['src/Foo.php', 'src/Missing.php']);

        $this->assertSame(
            ContentHash::of($this->repo->path().'/src/Foo.php'),
            $snapshot['src/Foo.php'],
        );
        $this->assertSame('', $snapshot['src/Missing.php']);
    }
}
