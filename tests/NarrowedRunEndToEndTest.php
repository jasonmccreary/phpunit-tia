<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A narrowed run keeps the baseline where it was, yet it runs affected tests
 * against code that differs from it. This runs the real PHPUnit binary against
 * a throwaway project to check that a pass earned on such a change is not
 * replayed once the code is back to the baseline.
 */
final class NarrowedRunEndToEndTest extends TestCase
{
    private TempGitRepository $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = TempGitRepository::create();
        $this->project->write('bootstrap.php', "<?php\n\nrequire ".var_export(dirname(__DIR__).'/vendor/autoload.php', true).";\n\nrequire_once __DIR__.'/src/Calculator.php';\n");
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
        $this->writeCalculator('$value');
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
        $this->project->commit('init');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function it_does_not_replay_a_pass_from_a_narrowed_run_once_its_change_is_reverted(): void
    {
        $baseline = $this->phpunit();
        $this->assertStringContainsString('FAILURES!', $baseline->getOutput());

        $this->writeCalculator('$value * 2');

        $fixed = $this->phpunit('--filter', 'test_it_doubles');
        $this->assertTrue($fixed->isSuccessful(), $fixed->getOutput().$fixed->getErrorOutput());

        $this->project->run(['git', 'checkout', '--', 'src/Calculator.php']);

        $reverted = $this->phpunit();
        $this->assertStringContainsString('FAILURES!', $reverted->getOutput(), 'The pass the narrowed run earned on the fix must not replay over the reverted code.');
    }

    private function writeCalculator(string $expression): void
    {
        $this->project->write('src/Calculator.php', <<<PHP
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

    private function phpunit(string ...$arguments): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__).'/vendor/bin/phpunit', '--no-progress', ...$arguments],
            $this->project->path(),
        );
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
