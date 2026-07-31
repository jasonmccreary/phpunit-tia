<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ContentHash;
use PHPUnit\Framework\Attributes\Test;

final class ContentHashTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/phpunit-tia-content-hash-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->skippedByTia()) {
            return;
        }

        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tempDir);
    }

    #[Test]
    public function it_returns_false_for_a_missing_file(): void
    {
        $this->assertFalse(ContentHash::of($this->tempDir.'/missing.php'));
    }

    #[Test]
    public function it_hashes_non_php_files_on_raw_content(): void
    {
        $path = $this->write('note.txt', "hello\n");

        $this->assertSame(hash('xxh128', "hello\n"), ContentHash::of($path));
    }

    #[Test]
    public function whitespace_only_changes_to_php_produce_the_same_hash(): void
    {
        $a = ContentHash::ofContent('a.php', "<?php\n\nfunction foo() {\n    return 1;\n}\n");
        $b = ContentHash::ofContent('b.php', "<?php\nfunction foo(){return 1;}\n");

        $this->assertSame($a, $b);
    }

    #[Test]
    public function comment_only_changes_to_php_produce_the_same_hash(): void
    {
        $a = ContentHash::ofContent('a.php', "<?php\nfunction foo() {\n    return 1;\n}\n");
        $b = ContentHash::ofContent('b.php', "<?php\n// explains foo\nfunction foo() {\n    /** returns one */\n    return 1;\n}\n");

        $this->assertSame($a, $b);
    }

    #[Test]
    public function a_real_code_change_produces_a_different_hash(): void
    {
        $a = ContentHash::ofContent('a.php', "<?php\nfunction foo() {\n    return 1;\n}\n");
        $b = ContentHash::ofContent('b.php', "<?php\nfunction foo() {\n    return 2;\n}\n");

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function it_is_case_insensitive_about_the_php_extension(): void
    {
        $lower = ContentHash::ofContent('a.php', "<?php\n// comment\necho 1;\n");
        $upper = ContentHash::ofContent('A.PHP', "<?php\necho 1;\n");

        $this->assertSame($lower, $upper);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
