<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\TestPaths;
use PHPUnit\Framework\Attributes\Test;

/**
 * fromProjectRoot() reads PHPUnit's process-global Registry singleton, which
 * (during this very test run) reflects this package's own phpunit.xml.dist:
 * <testsuite><directory>tests</directory></testsuite>. So these tests
 * exercise it against this project's real, live configuration rather than a
 * fabricated one — there's no seam to inject a fake Configuration into
 * Registry from outside a real PHPUnit run.
 */
final class TestPathsTest extends TestCase
{
    #[Test]
    public function it_recognizes_a_file_under_the_configured_test_directory(): void
    {
        $testPaths = TestPaths::fromProjectRoot(dirname(__DIR__));

        $this->assertTrue($testPaths->isTestFile('tests/GraphTest.php'));
    }

    #[Test]
    public function it_does_not_treat_source_files_as_test_files(): void
    {
        $testPaths = TestPaths::fromProjectRoot(dirname(__DIR__));

        $this->assertFalse($testPaths->isTestFile('src/Graph.php'));
    }

    #[Test]
    public function it_does_not_match_a_path_that_merely_starts_with_the_directory_name(): void
    {
        $testPaths = TestPaths::fromProjectRoot(dirname(__DIR__));

        // "testsomething/Foo.php" must not match the "tests" prefix.
        $this->assertFalse($testPaths->isTestFile('testsomething/Foo.php'));
    }

    #[Test]
    public function an_explicit_files_list_matches_regardless_of_directory(): void
    {
        $testPaths = new TestPaths(directories: [], files: ['bin/smoke-test.php'], suffixes: ['Test.php']);

        $this->assertTrue($testPaths->isTestFile('bin/smoke-test.php'));
    }

    #[Test]
    public function it_matches_on_configured_suffix_within_a_configured_directory(): void
    {
        $testPaths = new TestPaths(directories: ['tests'], files: [], suffixes: ['Test.php']);

        $this->assertTrue($testPaths->isTestFile('tests/Feature/FooTest.php'));
        $this->assertFalse($testPaths->isTestFile('tests/Feature/Helper.php'));
        $this->assertFalse($testPaths->isTestFile('src/FooTest.php'));
    }

    #[Test]
    public function no_suffix_configured_means_nothing_matches_on_suffix(): void
    {
        $testPaths = new TestPaths(directories: ['tests'], files: [], suffixes: []);

        $this->assertFalse($testPaths->isTestFile('tests/FooTest.php'));
    }
}
