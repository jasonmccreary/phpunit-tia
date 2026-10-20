<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * PHPUnit records an inherited test method's coverage under the file that
 * declares it, while the concrete test class supplies the rest of its
 * behaviour. This runs the real PHPUnit binary against a throwaway project to
 * check that a change to the concrete class runs the inherited test again.
 */
final class InheritedTestEndToEndTest extends TestCase
{
    private TempGitRepository $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = TempGitRepository::create();
        $this->project->write('bootstrap.php', "<?php\n\nrequire ".var_export(dirname(__DIR__).'/vendor/autoload.php', true).";\n\nrequire_once __DIR__.'/src/Calculator.php';\nrequire_once __DIR__.'/tests/CalculatorCase.php';\n");
        $this->project->write('.gitignore', ".phpunit-tia/\n");
        $this->project->write('phpunit.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="bootstrap.php" colors="false">
                <testsuites>
                    <testsuite name="unit">
                        <directory suffix="Test.php">tests</directory>
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
        $this->project->write('src/Calculator.php', <<<'PHP'
            <?php

            final class Calculator
            {
                public function double(int $value): int
                {
                    return $value * 2;
                }
            }
            PHP);
        $this->project->write('tests/CalculatorCase.php', <<<'PHP'
            <?php

            abstract class CalculatorCase extends \PHPUnit\Framework\TestCase
            {
                use \JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;

                abstract protected function input(): int;

                abstract protected function expected(): int;

                public function test_it_doubles(): void
                {
                    $this->assertSame($this->expected(), (new Calculator)->double($this->input()));
                }
            }
            PHP);
        $this->writeConcreteTest(2);
        $this->project->commit('init');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function it_runs_an_inherited_test_again_when_its_concrete_class_changes(): void
    {
        $recording = $this->phpunit();
        $this->assertTrue($recording->isSuccessful(), $recording->getOutput().$recording->getErrorOutput());

        $this->writeConcreteTest(3);

        $changed = $this->phpunit();
        $this->assertStringContainsString('FAILURES!', $changed->getOutput(), 'The inherited test must run against the changed concrete class, not replay its pass.');
    }

    private function writeConcreteTest(int $expected): void
    {
        $this->project->write('tests/DoubleOneTest.php', <<<PHP
            <?php

            final class DoubleOneTest extends CalculatorCase
            {
                protected function input(): int
                {
                    return 1;
                }

                protected function expected(): int
                {
                    return {$expected};
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
