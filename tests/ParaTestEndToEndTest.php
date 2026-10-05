<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ParallelRunTest covers the shard merging in-process; this runs the real
 * ParaTest binary against a throwaway project, so a ParaTest release that
 * changes how the parent or workers bootstrap the extension fails here
 * instead of silently turning recording off.
 */
final class ParaTestEndToEndTest extends TestCase
{
    private const array TEST_FILES = ['AlphaTest', 'BetaTest', 'GammaTest', 'DeltaTest'];

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

        foreach (self::TEST_FILES as $name) {
            $class = 'Subject'.$name;

            $this->project->write("src/{$class}.php", <<<PHP
                <?php

                final class {$class}
                {
                    public function value(): int
                    {
                        return 1;
                    }
                }
                PHP);
            $this->project->write("tests/{$name}.php", <<<PHP
                <?php

                final class {$name} extends \\PHPUnit\\Framework\\TestCase
                {
                    public function test_it_passes(): void
                    {
                        \$this->assertSame(1, (new {$class})->value());
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
    public function it_records_every_worker_and_replays_from_the_merged_graph(): void
    {
        $recording = $this->paratest();

        $this->assertTrue($recording->isSuccessful(), $recording->getOutput().$recording->getErrorOutput());
        $this->assertStringNotContainsString('baseline not advanced', $recording->getErrorOutput());
        $this->assertStringNotContainsString('without a coordinating parent', $recording->getErrorOutput());

        $graph = json_decode((string) file_get_contents($this->project->path().'/.phpunit-tia/graph.json'), true);
        $this->assertIsArray($graph);

        foreach (self::TEST_FILES as $name) {
            $this->assertStringContainsString("tests/{$name}.php", json_encode($graph, JSON_UNESCAPED_SLASHES));
            $this->assertStringContainsString("src/Subject{$name}.php", json_encode($graph, JSON_UNESCAPED_SLASHES));
        }

        $this->assertSame([], glob($this->project->path().'/.phpunit-tia/parallel-*'), 'shards are cleaned up after the merge');

        $replay = $this->paratest();

        $this->assertTrue($replay->isSuccessful(), $replay->getOutput().$replay->getErrorOutput());
        $this->assertStringContainsString('phpunit-tia: 0 of 4 test files affected.', $replay->getErrorOutput());
    }

    private function paratest(): Process
    {
        $process = new Process(
            [dirname(__DIR__).'/vendor/bin/paratest', '--processes=2', '--no-progress'],
            $this->project->path(),
        );
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
