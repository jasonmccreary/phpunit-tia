<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests\Cli;

use JMac\Testing\PhpUnit\Tia\Cli\SuiteFiles;
use JMac\Testing\PhpUnit\Tia\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\TextUI\CliArguments\Builder as CliBuilder;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use ReflectionProperty;

final class SuiteFilesTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * The binary runs before PHPUnit boots, so it has to populate the
     * Configuration registry itself. Swapping the process-wide singleton is
     * restorable, which is the line drawn in GraphTest for the same reason.
     */
    private function withProjectConfiguration(callable $assertions): void
    {
        $registry = new ReflectionProperty(Registry::class, 'instance');
        $original = $registry->getValue();

        try {
            Registry::init(
                (new CliBuilder)->fromParameters([]),
                (new Loader)->load(realpath(self::ROOT).'/phpunit.xml.dist'),
            );

            $assertions();
        } finally {
            $registry->setValue(null, $original);
        }
    }

    /**
     * --testsuite restricts the run to named suites, so files from the others
     * must not be selected. Missing this makes the wrapper run tests the user
     * excluded, and pulls in every never-recorded file from those suites.
     */
    #[Test]
    public function it_honours_a_testsuite_restriction(): void
    {
        $this->withTwoSuiteConfiguration(['--testsuite', 'cli'], function (): void {
            $files = SuiteFiles::fromProjectRoot(realpath(self::ROOT))->all();

            $this->assertContains('tests/Cli/PlanTest.php', $files);
            $this->assertNotContains('tests/GraphTest.php', $files);
        });
    }

    #[Test]
    public function it_honours_an_excluded_testsuite(): void
    {
        $this->withTwoSuiteConfiguration(['--exclude-testsuite', 'cli'], function (): void {
            $files = SuiteFiles::fromProjectRoot(realpath(self::ROOT))->all();

            $this->assertNotContains('tests/Cli/PlanTest.php', $files);
            $this->assertContains('tests/GraphTest.php', $files);
        });
    }

    /**
     * @param  list<string>  $arguments
     */
    private function withTwoSuiteConfiguration(array $arguments, callable $assertions): void
    {
        $root = realpath(self::ROOT);
        $file = sys_get_temp_dir().'/phpunit-tia-suites-'.bin2hex(random_bytes(6)).'.xml';

        file_put_contents($file, <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="{$root}/vendor/autoload.php">
                <testsuites>
                    <testsuite name="cli">
                        <directory>{$root}/tests/Cli</directory>
                    </testsuite>
                    <testsuite name="rest">
                        <directory>{$root}/tests</directory>
                        <exclude>{$root}/tests/Cli</exclude>
                        <exclude>{$root}/tests/fixture-app</exclude>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML);

        $registry = new ReflectionProperty(Registry::class, 'instance');
        $original = $registry->getValue();

        try {
            Registry::init((new CliBuilder)->fromParameters($arguments), (new Loader)->load($file));

            $assertions();
        } finally {
            $registry->setValue(null, $original);

            @unlink($file);
        }
    }

    #[Test]
    public function it_lists_the_test_files_the_configured_suite_would_run(): void
    {
        $this->withProjectConfiguration(function (): void {
            $files = SuiteFiles::fromProjectRoot(realpath(self::ROOT))->all();

            $this->assertContains('tests/GraphTest.php', $files);
        });
    }

    /**
     * Positional paths bypass <exclude>, so an excluded file handed to PHPUnit
     * runs and can fatal. This list is what makes that unreachable.
     */
    #[Test]
    public function it_omits_files_the_suite_excludes(): void
    {
        $this->withProjectConfiguration(function (): void {
            $files = SuiteFiles::fromProjectRoot(realpath(self::ROOT))->all();

            $this->assertNotContains('tests/fixture-app/tests/WidgetTest.php', $files);
        });
    }
}
