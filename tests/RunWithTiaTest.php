<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ChangedFiles;
use JMac\Testing\PhpUnit\Tia\FileState;
use JMac\Testing\PhpUnit\Tia\Fingerprint;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use JMac\Testing\PhpUnit\Tia\Tia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestStatus\TestStatus;

/**
 * Exercises RunWithTia::setUp() directly (§4.7) against a scratch repo with
 * a pre-recorded graph, rather than through a full PHPUnit subprocess — that
 * end-to-end path (including the real --fail-on-skipped interaction) is
 * validated separately in fixture-app/ (see docs/TIA.md milestone 4 notes).
 */
final class RunWithTiaTest extends TestCase
{
    private TempGitRepository $repo;

    private ?string $fixtureClass = null;

    protected function tearDown(): void
    {
        Tia::reset();

        if (isset($this->repo)) {
            $this->repo->cleanup();
        }
    }

    #[Test]
    public function it_skips_a_known_unaffected_cached_pass_and_carries_the_assertion_count_forward(): void
    {
        $sha = $this->recordPassingResult(assertions: 5);
        $fixture = $this->fixtureInstance();

        try {
            $fixture->setUp();
            $this->fail('Expected setUp() to skip the test via TIA.');
        } catch (SkippedWithMessageException $e) {
            $this->assertSame("TIA: unaffected since {$sha}, last run passed", $e->getMessage());
        }

        $this->assertSame(5, $fixture->numberOfAssertionsPerformed());
    }

    #[Test]
    public function it_skips_a_known_unaffected_cached_pass_for_a_data_provided_test(): void
    {
        $sha = $this->recordPassingDataProvidedResult(dataSetName: 3, assertions: 2);
        $fixture = $this->fixtureInstance(dataName: 3);

        try {
            $fixture->setUp();
            $this->fail('Expected setUp() to skip the test via TIA.');
        } catch (SkippedWithMessageException $e) {
            $this->assertSame("TIA: unaffected since {$sha}, last run passed", $e->getMessage());
        }

        $this->assertSame(2, $fixture->numberOfAssertionsPerformed());
    }

    #[Test]
    public function it_reports_skippedByTia_true_only_after_a_tia_skip(): void
    {
        $this->recordPassingResult();
        $fixture = $this->fixtureInstance();

        $this->assertFalse($fixture->skippedByTiaForTest());

        try {
            $fixture->setUp();
            $this->fail('Expected setUp() to skip the test via TIA.');
        } catch (SkippedWithMessageException) {
            // expected
        }

        $this->assertTrue($fixture->skippedByTiaForTest());
    }

    #[Test]
    public function it_does_not_skip_when_the_source_file_changed(): void
    {
        $this->recordPassingResult();

        // Real token change (not just whitespace/comments — see TiaTest).
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n    public int \$x = 1;\n}\n");
        Tia::configure($this->repo->path(), 'local');

        $fixture = $this->fixtureInstance();
        $fixture->setUp();

        $this->assertSame(0, $fixture->numberOfAssertionsPerformed());
    }

    #[Test]
    public function it_does_not_skip_when_disabled_via_env(): void
    {
        $this->recordPassingResult();

        putenv('PHPUNIT_TIA=0');

        try {
            Tia::configure($this->repo->path(), 'local');
            $fixture = $this->fixtureInstance();
            $fixture->setUp();
        } finally {
            putenv('PHPUNIT_TIA');
        }

        $this->assertSame(0, $fixture->numberOfAssertionsPerformed());
    }

    #[Test]
    public function it_does_not_skip_a_test_unknown_to_the_graph(): void
    {
        $this->repo = TempGitRepository::create();
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");
        $this->fixtureClass = $this->defineFixtureClass();
        $this->repo->commit('add Foo + fixture test, no graph recorded yet');

        Tia::configure($this->repo->path(), 'local');

        $fixture = $this->fixtureInstance();
        $fixture->setUp();

        $this->assertSame(0, $fixture->numberOfAssertionsPerformed());
    }

    private function recordPassingResult(int $assertions = 3): string
    {
        $this->repo = TempGitRepository::create();
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");

        $class = $this->defineFixtureClass();
        $sha = $this->repo->commit('add Foo + fixture test');

        $graph = new Graph($this->repo->path());
        $graph->link($this->repo->path().'/tests/FixtureUsingTia.php', $this->repo->path().'/src/Foo.php');
        $graph->setResult(
            'main',
            $class.'::test_it_works',
            TestStatus::success()->asInt(),
            '',
            0.01,
            $assertions,
            'tests/FixtureUsingTia.php',
        );
        $graph->setFingerprint(Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', $sha);

        $changedFiles = new ChangedFiles($this->repo->path());
        $graph->setLastRunTree('main', $changedFiles->snapshotTree(['src/Foo.php', 'tests/FixtureUsingTia.php']));

        $state = new FileState(Storage::resolve($this->repo->path(), 'local'));
        $state->write(Storage::GRAPH_KEY, (string) $graph->encode());

        Tia::configure($this->repo->path(), 'local');
        $this->fixtureClass = $class;

        return $sha;
    }

    /**
     * Declares a fresh, uniquely-named TestCase subclass using RunWithTia
     * inside the scratch repo, so ReflectionClass::getFileName() (which Tia
     * uses to resolve the test's file) matches the linked graph edge instead
     * of this test file's own real path.
     */
    private function defineFixtureClass(): string
    {
        $class = 'FixtureUsingTia'.bin2hex(random_bytes(6));
        $path = $this->repo->path().'/tests/FixtureUsingTia.php';

        $code = <<<PHP
        <?php

        declare(strict_types=1);

        use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;
        use PHPUnit\Framework\TestCase;

        final class {$class} extends TestCase
        {
            use RunWithTia;

            public function test_it_works(): void
            {
                \$this->assertTrue(true);
            }

            public function skippedByTiaForTest(): bool
            {
                return \$this->skippedByTia();
            }
        }

        PHP;

        $this->repo->write('tests/FixtureUsingTia.php', $code);
        require $path;

        return $class;
    }

    /**
     * Same as defineFixtureClass(), but test_it_works() is data-provided —
     * this reproduces a regression where the read side (keyed off
     * TestCase::name(), no data-set suffix) missed the write side (keyed
     * off TestMethod::id(), suffixed with '#<dataSetName>').
     */
    private function defineFixtureClassWithDataProvider(): string
    {
        $class = 'FixtureUsingTia'.bin2hex(random_bytes(6));
        $path = $this->repo->path().'/tests/FixtureUsingTia.php';

        $code = <<<PHP
        <?php

        declare(strict_types=1);

        use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;
        use PHPUnit\Framework\Attributes\DataProvider;
        use PHPUnit\Framework\TestCase;

        final class {$class} extends TestCase
        {
            use RunWithTia;

            #[DataProvider('provideCases')]
            public function test_it_works(int \$value): void
            {
                \$this->assertTrue(true);
            }

            public static function provideCases(): array
            {
                return [[1], [2], [3], [4]];
            }
        }

        PHP;

        $this->repo->write('tests/FixtureUsingTia.php', $code);
        require $path;

        return $class;
    }

    private function recordPassingDataProvidedResult(int|string $dataSetName, int $assertions = 3): string
    {
        $this->repo = TempGitRepository::create();
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");

        $class = $this->defineFixtureClassWithDataProvider();
        $sha = $this->repo->commit('add Foo + data-provided fixture test');

        $method = 'test_it_works#'.$dataSetName;

        $graph = new Graph($this->repo->path());
        $graph->link($this->repo->path().'/tests/FixtureUsingTia.php', $this->repo->path().'/src/Foo.php');
        $graph->setResult(
            'main',
            $class.'::'.$method,
            TestStatus::success()->asInt(),
            '',
            0.01,
            $assertions,
            'tests/FixtureUsingTia.php',
        );
        $graph->setFingerprint(Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', $sha);

        $changedFiles = new ChangedFiles($this->repo->path());
        $graph->setLastRunTree('main', $changedFiles->snapshotTree(['src/Foo.php', 'tests/FixtureUsingTia.php']));

        $state = new FileState(Storage::resolve($this->repo->path(), 'local'));
        $state->write(Storage::GRAPH_KEY, (string) $graph->encode());

        Tia::configure($this->repo->path(), 'local');
        $this->fixtureClass = $class;

        return $sha;
    }

    private function fixtureInstance(int|string $dataName = ''): TestCase
    {
        $class = $this->fixtureClass;

        $test = new $class('test_it_works');

        if ($dataName !== '') {
            $test->setData($dataName, [$dataName]);
        }

        return $test;
    }
}
