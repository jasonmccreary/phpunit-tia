<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A full run takes its baseline tree when it starts, while the stored graph
 * still knows a source deleted since the last run. This runs the real PHPUnit
 * binary against a throwaway project to check that the deleted source stops
 * counting as a change once a full run has seen it gone.
 */
final class DeletedSourceEndToEndTest extends TestCase
{
    private TempGitRepository $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = TempGitRepository::create();
        $this->project->write('bootstrap.php', "<?php\n\nrequire ".var_export(dirname(__DIR__).'/vendor/autoload.php', true).";\n\nforeach (glob(__DIR__.'/src/*.php') as \$file) {\n    require_once \$file;\n}\n");
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

        foreach (['Kept', 'Gone'] as $name) {
            $this->project->write("src/{$name}.php", <<<PHP
                <?php

                final class {$name}
                {
                    public function value(): int
                    {
                        return 1;
                    }
                }
                PHP);
            $this->project->write("tests/{$name}Test.php", <<<PHP
                <?php

                final class {$name}Test extends \\PHPUnit\\Framework\\TestCase
                {
                    use \\JMac\\Testing\\PhpUnit\\Tia\\Traits\\RunWithTia;

                    public function test_it_works(): void
                    {
                        \$this->assertSame(1, (new {$name})->value());
                    }
                }
                PHP);
        }

        $this->project->commit('init');
    }

    protected function tearDown(): void
    {
        $this->project->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function a_deleted_source_stops_counting_as_a_change_after_a_full_run(): void
    {
        $recording = $this->phpunit();
        $this->assertTrue($recording->isSuccessful(), $recording->getOutput().$recording->getErrorOutput());

        $this->project->run(['git', 'rm', '-q', 'src/Gone.php', 'tests/GoneTest.php']);
        $this->project->commit('delete Gone');

        $afterDeletion = $this->phpunit();
        $this->assertTrue($afterDeletion->isSuccessful(), $afterDeletion->getOutput().$afterDeletion->getErrorOutput());

        $next = $this->phpunit();
        $this->assertStringContainsString('phpunit-tia: 0 of 1 test files affected.', $next->getErrorOutput());
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
