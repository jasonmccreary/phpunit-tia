<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A test whose setUp() starts to throw or fail never reaches Prepared. This
 * runs the real PHPUnit binary against a throwaway project to check that the
 * outcome is still recorded, so the next run does not replay the test's
 * previous pass over code that breaks it.
 */
final class SetUpOutcomeEndToEndTest extends TestCase
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
        $this->writeBoot('');
        $this->project->write('tests/FooTest.php', <<<'PHP'
            <?php

            final class FooTest extends \PHPUnit\Framework\TestCase
            {
                use \JMac\Testing\PhpUnit\Tia\Traits\RunWithTia {
                    setUp as tiaSetUp;
                }

                protected function setUp(): void
                {
                    $this->tiaSetUp();

                    $this->assertTrue(Boot::up());
                }

                public function test_it_works(): void
                {
                    $this->assertTrue(true);
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function brokenSetUps(): array
    {
        return [
            'setUp throws' => ['throw new RuntimeException(\'boot failed\');', 'ERRORS!'],
            'setUp fails an assertion' => ['return false;', 'FAILURES!'],
        ];
    }

    #[Test]
    #[DataProvider('brokenSetUps')]
    public function it_keeps_reporting_a_test_whose_set_up_broke(string $body, string $verdict): void
    {
        $recording = $this->phpunit();
        $this->assertTrue($recording->isSuccessful(), $recording->getOutput().$recording->getErrorOutput());

        $this->writeBoot($body);

        $broken = $this->phpunit();
        $this->assertStringContainsString($verdict, $broken->getOutput());

        $again = $this->phpunit();
        $this->assertStringContainsString($verdict, $again->getOutput(), 'The broken setUp() must not be replayed as the earlier pass.');
    }

    #[Test]
    public function it_still_replays_a_test_whose_set_up_is_fine(): void
    {
        $this->assertTrue($this->phpunit()->isSuccessful());

        foreach ([1, 2] as $run) {
            $replay = $this->phpunit();

            $this->assertStringContainsString('OK, but some tests were skipped!', $replay->getOutput(), "Run {$run} must replay the cached pass.");
        }
    }

    private function writeBoot(string $body): void
    {
        $this->project->write('src/Boot.php', <<<PHP
            <?php

            final class Boot
            {
                public static function up(): bool
                {
                    {$body}

                    return true;
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
