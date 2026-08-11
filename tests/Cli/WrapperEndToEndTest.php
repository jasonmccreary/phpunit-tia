<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Drives the real binary as a subprocess against tests/fixture-app, a small
 * project wired exactly as a consumer's would be.
 *
 * The unit tests cover each part in isolation; this covers the one thing they
 * cannot — that the shim, the planner and the re-exec actually compose when
 * run the way a user runs them.
 *
 * Each case gets its own HOME so the graph under test is never the developer's
 * real one, and the fixture's sources are restored afterwards.
 */
final class WrapperEndToEndTest extends TestCase
{
    private string $app;

    private string $home;

    /** @var array<string, string> */
    private array $originalSources = [];

    protected function setUp(): void
    {
        $this->app = dirname(__DIR__).'/fixture-app';

        if (! is_file($this->app.'/vendor/autoload.php')) {
            $this->markTestSkipped('fixture-app dependencies are not installed — run `composer install` in tests/fixture-app.');
        }

        if (! extension_loaded('pcov') && ! extension_loaded('xdebug')) {
            $this->markTestSkipped('recording a baseline needs a coverage driver.');
        }

        $this->home = sys_get_temp_dir().'/phpunit-tia-e2e-'.bin2hex(random_bytes(6));

        mkdir($this->home);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSources as $path => $contents) {
            file_put_contents($path, $contents);
        }

        $this->originalSources = [];

        if (is_dir($this->home)) {
            $this->removeDirectory($this->home);
        }
    }

    #[Test]
    public function it_runs_the_whole_suite_when_there_is_no_baseline(): void
    {
        $run = $this->wrapper();

        $this->assertStringContainsString('no usable baseline graph', $run->getErrorOutput());
        $this->assertStringContainsString('OK (3 tests', $run->getOutput());
    }

    #[Test]
    public function it_runs_nothing_once_the_baseline_is_warm_and_nothing_changed(): void
    {
        $this->recordBaseline();

        $run = $this->wrapper();

        $this->assertSame(0, $run->getExitCode());
        $this->assertStringContainsString('nothing affected', $run->getErrorOutput());
        $this->assertStringNotContainsString('OK (', $run->getOutput());
    }

    #[Test]
    public function it_selects_only_the_test_files_covering_a_changed_source(): void
    {
        $this->recordBaseline();
        $this->changeCalculator();

        $run = $this->wrapper();

        $this->assertStringContainsString('1 of 2 test files selected', $run->getErrorOutput());
        $this->assertStringContainsString('OK (2 tests', $run->getOutput());
    }

    /**
     * The trait can only skip, so --fail-on-skipped forces it to give up its
     * speed-up entirely and run everything. Selecting up front produces no
     * skips at all, so the flag and the speed-up finally coexist.
     */
    #[Test]
    public function it_keeps_the_speed_up_under_fail_on_skipped(): void
    {
        $this->recordBaseline();
        $this->changeCalculator();

        $run = $this->wrapper(['--fail-on-skipped']);

        $this->assertSame(0, $run->getExitCode());
        $this->assertStringContainsString('1 of 2 test files selected', $run->getErrorOutput());
        $this->assertStringContainsString('OK (2 tests', $run->getOutput());
    }

    #[Test]
    public function it_passes_options_through_to_the_real_runner(): void
    {
        $this->recordBaseline();
        $this->changeCalculator();

        $run = $this->wrapper(['--filter', 'test_it_adds']);

        $this->assertStringContainsString('1 of 2 test files selected', $run->getErrorOutput());
        $this->assertStringContainsString('OK (1 test', $run->getOutput());
    }

    #[Test]
    public function it_runs_the_whole_suite_when_disabled_by_environment(): void
    {
        $this->recordBaseline();

        $run = $this->wrapper(env: ['PHPUNIT_TIA' => '0']);

        $this->assertStringContainsString('OK (3 tests', $run->getOutput());
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    private function wrapper(array $arguments = [], array $env = []): Process
    {
        return $this->process(
            [PHP_BINARY, 'vendor/bin/phpunit-tia', ...$arguments],
            $env,
        );
    }

    private function recordBaseline(): void
    {
        $run = $this->process([PHP_BINARY, '-d', 'pcov.enabled=1', '-d', 'pcov.directory=.', 'vendor/bin/phpunit']);

        $this->assertStringContainsString('OK (3 tests', $run->getOutput(), 'baseline run should pass');
    }

    private function changeCalculator(): void
    {
        $path = $this->app.'/src/Calculator.php';
        $this->originalSources[$path] = (string) file_get_contents($path);

        $contents = $this->originalSources[$path];
        $position = strrpos($contents, '}');

        file_put_contents($path, substr($contents, 0, $position)
            ."\n    public function triple(int \$number): int\n    {\n        return \$number * 3;\n    }\n"
            .substr($contents, $position));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function process(array $command, array $env = []): Process
    {
        $process = new Process($command, $this->app, ['HOME' => $this->home] + $env, timeout: 120);

        $process->run();

        return $process;
    }

    private function removeDirectory(string $directory): void
    {
        foreach ((array) scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
