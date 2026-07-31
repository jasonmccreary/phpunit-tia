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
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestStatus\TestStatus;

/**
 * Exercises Tia's replay decision (§4.7) against a scratch git repo, wired
 * through the real Storage/FileState/Fingerprint/ChangedFiles/Graph stack —
 * everything except an actual coverage session, which milestone 3's
 * WriteGraph end-to-end run already validated in fixture-app/.
 */
final class TiaTest extends TestCase
{
    private TempGitRepository $repo;

    protected function setUp(): void
    {
        $this->repo = TempGitRepository::create();
    }

    protected function tearDown(): void
    {
        Tia::reset();
        $this->repo->cleanup();
    }

    #[Test]
    public function it_is_inactive_when_never_configured(): void
    {
        $this->assertNull(Tia::instance()->cachedStatusIfUnaffected('AnyClass', 'any_method'));
    }

    #[Test]
    public function it_is_inactive_when_disabled_via_env(): void
    {
        [$class, $method, $sha] = $this->recordPassingTest();

        putenv('PHPUNIT_TIA=0');

        try {
            Tia::configure($this->repo->path(), 'local');
            $this->assertNull(Tia::instance()->cachedStatusIfUnaffected($class, $method));
        } finally {
            putenv('PHPUNIT_TIA');
        }
    }

    #[Test]
    public function it_is_inactive_when_fresh_via_env(): void
    {
        [$class, $method, $sha] = $this->recordPassingTest();

        putenv('PHPUNIT_TIA_FRESH=1');

        try {
            Tia::configure($this->repo->path(), 'local');
            $this->assertNull(Tia::instance()->cachedStatusIfUnaffected($class, $method));
        } finally {
            putenv('PHPUNIT_TIA_FRESH');
        }
    }

    #[Test]
    public function it_replays_a_known_unaffected_passing_test(): void
    {
        [$class, $method, $sha] = $this->recordPassingTest();

        Tia::configure($this->repo->path(), 'local');
        $tia = Tia::instance();

        $status = $tia->cachedStatusIfUnaffected($class, $method);

        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
        $this->assertSame(3, $tia->cachedAssertionCount($class, $method));
        $this->assertSame($sha, $tia->recordedAtSha());
    }

    #[Test]
    public function it_does_not_replay_a_test_whose_source_file_changed(): void
    {
        [$class, $method] = $this->recordPassingTest();

        // Uncommitted change to the linked source file since the recorded
        // sha. A real token change, not just whitespace/comments — those are
        // stripped by ContentHash::hashPhpContent() and deliberately treated
        // as behaviorally unchanged (§4.2).
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n    public int \$x = 1;\n}\n");

        Tia::configure($this->repo->path(), 'local');

        $this->assertNull(Tia::instance()->cachedStatusIfUnaffected($class, $method));
    }

    #[Test]
    public function it_does_not_replay_a_test_unknown_to_the_graph(): void
    {
        $this->recordPassingTest();

        Tia::configure($this->repo->path(), 'local');

        $unknownClass = $this->defineFixtureClass('tests/UnknownTest.php');

        $this->assertNull(Tia::instance()->cachedStatusIfUnaffected($unknownClass, 'test_something'));
    }

    #[Test]
    public function it_never_replays_a_cached_failure(): void
    {
        [$class, $method] = $this->recordTest(TestStatus::failure('boom'));

        Tia::configure($this->repo->path(), 'local');

        $this->assertNull(Tia::instance()->cachedStatusIfUnaffected($class, $method));
    }

    #[Test]
    public function it_is_inactive_when_the_recorded_sha_is_unreachable(): void
    {
        [$class, $method] = $this->recordPassingTest(sha: str_repeat('a', 40));

        Tia::configure($this->repo->path(), 'local');

        $this->assertNull(Tia::instance()->cachedStatusIfUnaffected($class, $method));
    }

    #[Test]
    public function it_is_inactive_on_structural_fingerprint_drift(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->path());
        $fingerprint['structural']['composer_lock'] = 'stale-hash-from-a-previous-package-version';

        [$class, $method] = $this->recordPassingTest(fingerprint: $fingerprint);

        Tia::configure($this->repo->path(), 'local');

        $this->assertNull(Tia::instance()->cachedStatusIfUnaffected($class, $method));
    }

    #[Test]
    public function it_is_inactive_on_environmental_fingerprint_drift(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->path());
        $fingerprint['environmental']['php_version'] = '1.0';

        [$class, $method] = $this->recordPassingTest(fingerprint: $fingerprint);

        Tia::configure($this->repo->path(), 'local');

        $this->assertNull(Tia::instance()->cachedStatusIfUnaffected($class, $method));
    }

    #[Test]
    public function it_delegates_should_rerun_status_to_the_graph(): void
    {
        $this->recordPassingTest();

        Tia::configure($this->repo->path(), 'local');
        $tia = Tia::instance();

        $this->assertTrue($tia->shouldRerunStatus(TestStatus::failure('boom')));
        $this->assertTrue($tia->shouldRerunStatus(TestStatus::error('boom')));
        $this->assertFalse($tia->shouldRerunStatus(TestStatus::success()));
    }

    #[Test]
    public function it_never_reruns_by_default_when_inactive(): void
    {
        $this->assertTrue(Tia::instance()->shouldRerunStatus(TestStatus::success()));
        $this->assertNull(Tia::instance()->recordedAtSha());
        $this->assertSame(0, Tia::instance()->cachedAssertionCount('AnyClass', 'any_method'));
    }

    /**
     * @return array{0: string, 1: string, 2: string} [className, methodName, sha]
     */
    private function recordPassingTest(?string $sha = null, ?array $fingerprint = null): array
    {
        return $this->recordTest(TestStatus::success(), $sha, $fingerprint);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [className, methodName, sha]
     */
    private function recordTest(TestStatus $status, ?string $sha = null, ?array $fingerprint = null): array
    {
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");
        $class = $this->defineFixtureClass('tests/FooTest.php');
        $method = 'test_it_works';

        $recordedSha = $this->repo->commit('add Foo + FooTest');

        $graph = new Graph($this->repo->path());
        $graph->link($this->repo->path().'/tests/FooTest.php', $this->repo->path().'/src/Foo.php');
        $graph->setResult('main', $class.'::'.$method, $status->asInt(), $status->message(), 0.01, 3, 'tests/FooTest.php');
        $graph->setFingerprint($fingerprint ?? Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', $sha ?? $recordedSha);

        $changedFiles = new ChangedFiles($this->repo->path());
        $graph->setLastRunTree('main', $changedFiles->snapshotTree(['src/Foo.php', 'tests/FooTest.php']));

        $state = new FileState(Storage::resolve($this->repo->path(), 'local'));
        $state->write(Storage::GRAPH_KEY, (string) $graph->encode());

        return [$class, $method, $sha ?? $recordedSha];
    }

    private function defineFixtureClass(string $relativePath): string
    {
        $class = 'TiaFixture'.bin2hex(random_bytes(6));
        $this->repo->write($relativePath, "<?php\n\nclass {$class}\n{\n}\n");
        require $this->repo->path().'/'.$relativePath;

        return $class;
    }
}
