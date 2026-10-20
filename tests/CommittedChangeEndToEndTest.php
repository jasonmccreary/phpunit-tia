<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A committed change has to reach the tests that cover it the same way an
 * uncommitted one does. This runs the real PHPUnit binary against a throwaway
 * project whose bootstrap loads every file under src/, so a file keeps its
 * class when its path changes.
 */
final class CommittedChangeEndToEndTest extends TestCase
{
    private TempGitRepository $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = TempGitRepository::create();
        $this->project->run(['git', 'config', 'core.quotePath', 'true']);
        $this->project->run(['git', 'config', 'diff.renames', 'true']);
        // Loads src/ one level deep, enough for the paths these tests use.
        $this->project->write('bootstrap.php', "<?php\n\nrequire ".var_export(dirname(__DIR__).'/vendor/autoload.php', true).";\n\nforeach ([...glob(__DIR__.'/src/*.php'), ...glob(__DIR__.'/src/*/*.php')] as \$file) {\n    require_once \$file;\n}\n");
        $this->project->write('.gitignore', ".phpunit-tia/\n");
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
                    <bootstrap class="JMac\Testing\PhpUnit\Tia\Extension">
                        <parameter name="storage" value="local"/>
                    </bootstrap>
                </extensions>
            </phpunit>
            XML);
        $this->project->write('tests/CalculatorTest.php', <<<'PHP'
            <?php

            final class CalculatorTest extends \PHPUnit\Framework\TestCase
            {
                use \JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;

                public function test_it_doubles(): void
                {
                    $this->assertSame(4, (new Calculator)->double(2));
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function it_runs_the_tests_of_a_committed_change_to_a_non_ascii_path(): void
    {
        $this->writeCalculator('src/Größe.php', '$value * 2');
        $this->project->commit('init');

        $baseline = $this->phpunit();
        $this->assertTrue($baseline->isSuccessful(), $baseline->getOutput().$baseline->getErrorOutput());

        $this->writeCalculator('src/Größe.php', '$value * 3');
        $this->project->commit('break the calculator');

        $changed = $this->phpunit();
        $this->assertStringContainsString('phpunit-tia: 1 of 1 test files affected.', $changed->getErrorOutput());
        $this->assertStringContainsString('FAILURES!', $changed->getOutput());
    }

    #[Test]
    public function it_runs_the_tests_of_a_committed_rename(): void
    {
        $this->writeCalculator('src/Calculator.php', '$value * 2');
        $this->project->commit('init');

        $baseline = $this->phpunit();
        $this->assertTrue($baseline->isSuccessful(), $baseline->getOutput().$baseline->getErrorOutput());

        $this->project->run(['git', 'rm', '-q', 'src/Calculator.php']);
        $this->writeCalculator('src/Math/Calculator.php', '$value * 3');
        $this->project->commit('move and break the calculator');

        $changed = $this->phpunit();
        $this->assertStringContainsString('phpunit-tia: 1 of 1 test files affected.', $changed->getErrorOutput());
        $this->assertStringContainsString('FAILURES!', $changed->getOutput());
    }

    private function writeCalculator(string $path, string $expression): void
    {
        $this->project->write($path, <<<PHP
            <?php

            final class Calculator
            {
                public function double(int \$value): int
                {
                    return {$expression};
                }
            }
            PHP);
    }

    private function phpunit(): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__).'/vendor/bin/phpunit', '--no-progress'],
            $this->project->path(),
        );
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
