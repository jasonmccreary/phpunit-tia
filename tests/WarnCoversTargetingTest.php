<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The warning is only meaningful once real coverage collection has run
 * `#[Covers*]` metadata through PHPUnit's own targeting filter, so this
 * shells out to a real `phpunit` run against a throwaway project (see
 * RunWithTiaTest's STDERR tests for the same constraint: fwrite(STDERR, ...)
 * can't be intercepted in-process) rather than constructing the Prepared
 * event by hand. It builds its own scratch project — rather than reusing
 * fixture-app/, whose vendor/ is gitignored and never installed in CI — and
 * runs this package's own already-installed `phpunit` binary against it.
 */
final class WarnCoversTargetingTest extends TestCase
{
    private TempGitRepository $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = TempGitRepository::create();
        $this->project->write('bootstrap.php', 'require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).";\n");
        $this->project->write('phpunit.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="bootstrap.php" colors="false">
                <testsuites>
                    <testsuite name="unit">
                        <directory>tests</directory>
                    </testsuite>
                </testsuites>
                <source>
                    <include>
                        <directory>src</directory>
                    </include>
                </source>
                <extensions>
                    <bootstrap class="JMac\Testing\PhpUnit\Tia\Extension"/>
                </extensions>
            </phpunit>
            XML);
        $this->project->write('src/Calculator.php', <<<'PHP'
            <?php

            final class Calculator
            {
                public function add(int $a, int $b): int
                {
                    return $a + $b;
                }
            }
            PHP);
        $this->project->commit('init');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function it_warns_once_when_a_test_uses_covers_metadata(): void
    {
        $this->writeTest(covers: true);

        $output = $this->runProject([]);

        $this->assertStringContainsString(
            'phpunit-tia: CoversTest::test_it_adds uses #[Covers*] metadata',
            $output,
        );
        $this->assertStringContainsString('--disable-coverage-targeting', $output);
    }

    #[Test]
    public function it_does_not_warn_when_coverage_targeting_is_already_disabled(): void
    {
        $this->writeTest(covers: true);

        $output = $this->runProject(['--disable-coverage-targeting']);

        $this->assertStringNotContainsString('phpunit-tia:', $output);
    }

    #[Test]
    public function it_does_not_warn_for_a_suite_without_covers_metadata(): void
    {
        $this->writeTest(covers: false);

        $output = $this->runProject([]);

        $this->assertStringNotContainsString('phpunit-tia:', $output);
    }

    private function writeTest(bool $covers): void
    {
        $attribute = $covers ? "#[\\PHPUnit\\Framework\\Attributes\\CoversClass(Calculator::class)]\n" : '';

        $this->project->write('tests/CoversTest.php', <<<PHP
            <?php

            {$attribute}final class CoversTest extends \\PHPUnit\\Framework\\TestCase
            {
                public function test_it_adds(): void
                {
                    \$this->assertSame(4, (new Calculator)->add(2, 2));
                }
            }
            PHP);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runProject(array $arguments): string
    {
        $process = new Process(
            [dirname(__DIR__).'/vendor/bin/phpunit', ...$arguments],
            $this->project->path(),
            ['PHPUNIT_TIA_FRESH' => '1'],
        );
        $process->run();

        return $process->getErrorOutput();
    }
}
