<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\FileState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileStateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/phpunit-tia-filestate-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root);
    }

    #[Test]
    public function read_returns_null_for_a_missing_key(): void
    {
        $state = new FileState($this->root);

        $this->assertNull($state->read('graph.json'));
    }

    #[Test]
    public function write_creates_the_root_directory_and_persists_content(): void
    {
        $state = new FileState($this->root);

        $this->assertDirectoryDoesNotExist($this->root);
        $this->assertTrue($state->write('graph.json', '{"schema":1}'));
        $this->assertDirectoryExists($this->root);
        $this->assertSame('{"schema":1}', $state->read('graph.json'));
    }

    #[Test]
    public function write_does_not_leave_a_tmp_file_behind(): void
    {
        $state = new FileState($this->root);
        $state->write('graph.json', 'content');

        $tmpFiles = glob($this->root.'/*.tmp') ?: [];

        $this->assertSame([], $tmpFiles);
    }

    #[Test]
    public function exists_reflects_whether_the_key_has_been_written(): void
    {
        $state = new FileState($this->root);

        $this->assertFalse($state->exists('graph.json'));
        $state->write('graph.json', 'content');
        $this->assertTrue($state->exists('graph.json'));
    }

    #[Test]
    public function delete_removes_the_key_and_returns_true_when_already_absent(): void
    {
        $state = new FileState($this->root);
        $state->write('graph.json', 'content');

        $this->assertTrue($state->delete('graph.json'));
        $this->assertFalse($state->exists('graph.json'));
        $this->assertTrue($state->delete('graph.json'));
    }

    #[Test]
    public function keys_with_prefix_lists_matching_keys_only(): void
    {
        $state = new FileState($this->root);
        $state->write('graph.json', 'a');
        $state->write('graph.backup.json', 'b');
        $state->write('other.json', 'c');

        $keys = $state->keysWithPrefix('graph');
        sort($keys);

        $this->assertSame(['graph.backup.json', 'graph.json'], $keys);
    }

    #[Test]
    public function keys_with_prefix_is_empty_when_the_root_was_never_created(): void
    {
        $state = new FileState($this->root);

        $this->assertSame([], $state->keysWithPrefix('graph'));
    }
}
