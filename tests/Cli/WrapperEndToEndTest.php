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

    /** @var list<string> */
    private array $createdFiles = [];

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

        foreach ($this->createdFiles as $path) {
            @unlink($path);
        }

        $this->originalSources = [];
        $this->createdFiles = [];

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

    /**
     * The trait replays only a cached success, so a test skipped for a missing
     * service is retried on every run and starts passing once that service is
     * up. Selection has to agree: the fingerprint tracks the PHP version and
     * the composer/phpunit files, so starting the service does not invalidate
     * the graph, and a selector that pruned the file would leave the test
     * unrun indefinitely.
     *
     * The test under measurement writes a marker before skipping itself, so
     * the marker's presence proves its body actually executed rather than
     * being replayed or never selected.
     */
    #[Test]
    public function it_reselects_a_test_that_skipped_itself_last_run(): void
    {
        $marker = $this->app.'/tia-e2e-marker.txt';

        $this->createFixtureTest('EnvSkippedTest', <<<PHP
            public function test_it_skips_when_a_service_is_missing(): void
            {
                file_put_contents('{$marker}', 'ran');

                \$this->markTestSkipped('service not available');
            }
            PHP);

        $this->createdFiles[] = $marker;

        $this->recordBaseline(expectedTests: 4);

        $this->assertFileExists($marker, 'the baseline run should execute the test body');

        unlink($marker);

        $run = $this->wrapper();

        $this->assertFileExists($marker, 'nothing changed, but the skipped test must be selected again');
        $this->assertStringContainsString('test files selected', $run->getErrorOutput());
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

    private function recordBaseline(int $expectedTests = 3): void
    {
        $run = $this->process([PHP_BINARY, '-d', 'pcov.enabled=1', '-d', 'pcov.directory=.', 'vendor/bin/phpunit']);

        // PHPUnit reports "OK (N tests, …)" for a clean run but "Tests: N, …"
        // once anything is skipped, and one case deliberately records a skip.
        $this->assertMatchesRegularExpression(
            "/(OK \({$expectedTests} tests|Tests: {$expectedTests}\b)/",
            $run->getOutput(),
            'baseline run should cover the whole fixture',
        );
    }

    /**
     * Writes an extra test class into the fixture for the duration of one
     * case. Keeping it out of the committed fixture matters: a permanently
     * skipped test would be selected on every run and would break the cases
     * that assert nothing is affected.
     */
    private function createFixtureTest(string $class, string $body): void
    {
        $path = $this->app."/tests/{$class}.php";

        file_put_contents($path, <<<PHP
            <?php

            namespace Tests;

            class {$class} extends TestCase
            {
            {$body}
            }

            PHP);

        $this->createdFiles[] = $path;
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
