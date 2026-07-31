<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    private TempGitRepository $repo;

    protected function setUp(): void
    {
        $this->repo = TempGitRepository::create();
    }

    protected function tearDown(): void
    {
        $this->repo->cleanup();

        Storage::purge($this->repo->path(), 'global');
    }

    #[Test]
    public function local_mode_resolves_inside_the_project(): void
    {
        $this->assertSame(
            $this->repo->path().'/.phpunit-tia',
            Storage::resolve($this->repo->path(), 'local'),
        );
    }

    #[Test]
    public function global_mode_resolves_outside_the_project_under_home(): void
    {
        $resolved = Storage::resolve($this->repo->path(), 'global');

        $this->assertStringStartsWith(rtrim((string) getenv('HOME'), '/').'/.phpunit-tia/', $resolved);
        $this->assertStringNotContainsString($this->repo->path(), $resolved);
    }

    #[Test]
    public function global_mode_is_deterministic_for_the_same_project(): void
    {
        $first = Storage::resolve($this->repo->path(), 'global');
        $second = Storage::resolve($this->repo->path(), 'global');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function global_mode_is_keyed_by_git_origin_when_present(): void
    {
        $withoutOrigin = Storage::resolve($this->repo->path(), 'global');

        $this->repo->run(['git', 'remote', 'add', 'origin', 'git@github.com:acme/widgets.git']);

        $withOrigin = Storage::resolve($this->repo->path(), 'global');

        $this->assertNotSame($withoutOrigin, $withOrigin);
    }

    #[Test]
    public function global_mode_is_stable_across_equivalent_origin_url_forms(): void
    {
        $this->repo->run(['git', 'remote', 'add', 'origin', 'git@github.com:acme/widgets.git']);
        $sshForm = Storage::resolve($this->repo->path(), 'global');

        $this->repo->run(['git', 'remote', 'set-url', 'origin', 'https://github.com/acme/widgets.git']);
        $httpsForm = Storage::resolve($this->repo->path(), 'global');

        $this->assertSame($sshForm, $httpsForm);
    }

    #[Test]
    public function purge_removes_a_resolved_directory(): void
    {
        $dir = Storage::resolve($this->repo->path(), 'global');
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/graph.json', '{}');

        Storage::purge($this->repo->path(), 'global');

        $this->assertDirectoryDoesNotExist($dir);
    }

    #[Test]
    public function purge_on_a_directory_that_never_existed_is_a_no_op(): void
    {
        Storage::purge($this->repo->path(), 'global');

        $this->addToAssertionCount(1);
    }
}
