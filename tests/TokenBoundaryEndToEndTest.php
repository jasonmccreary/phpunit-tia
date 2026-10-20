<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Turning `1 . 2` into `1.2` only removes whitespace, yet it turns a string
 * concatenation into a float. This runs the real PHPUnit binary against a
 * throwaway project to check that such an edit counts as a change, so the
 * test's previous pass is not replayed over code that breaks it.
 */
final class TokenBoundaryEndToEndTest extends TestCase
{
    private TempGitRepository $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = TempGitRepository::create();
        $this->project->write('bootstrap.php', "<?php\n\nrequire ".var_export(dirname(__DIR__).'/vendor/autoload.php', true).";\n\nrequire_once __DIR__.'/src/Version.php';\n");
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
        $this->writeVersion('1 . 2');
        $this->project->write('tests/VersionTest.php', <<<'PHP'
            <?php

            final class VersionTest extends \PHPUnit\Framework\TestCase
            {
                use \JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;

                public function test_it_is_a_string(): void
                {
                    $this->assertSame('12', (new Version)->value());
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
    public function it_runs_a_test_again_when_an_edit_only_moves_token_boundaries(): void
    {
        $baseline = $this->phpunit();
        $this->assertTrue($baseline->isSuccessful(), $baseline->getOutput().$baseline->getErrorOutput());

        $this->writeVersion('1.2');

        $changed = $this->phpunit();
        $this->assertStringContainsString('FAILURES!', $changed->getOutput(), 'The pass recorded for `1 . 2` must not replay over `1.2`.');
    }

    private function writeVersion(string $expression): void
    {
        $this->project->write('src/Version.php', <<<PHP
            <?php

            final class Version
            {
                public function value(): string|float
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
