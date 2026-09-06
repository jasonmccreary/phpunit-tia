<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ChangedFiles;
use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;
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
        // Every test here starts from a genuinely pristine Tia singleton —
        // tearDown() re-arms the real project's config afterward (for the
        // rest of the dogfooded suite), which would otherwise leak into
        // whichever test here runs next and expects "never configured".
        Tia::reset();

        $this->repo = TempGitRepository::create();
    }

    protected function tearDown(): void
    {
        Tia::reset();

        // The rest of this suite dogfoods TIA (tests/TestCase.php) against
        // this real project via the process-wide Tia singleton — reset()
        // alone leaves it unconfigured for every test after this one, so
        // re-arm it exactly as Extension::bootstrap() would.
        Tia::configure(dirname(__DIR__), 'global');

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
    public function it_replays_a_test_inherited_from_an_abstract_fixture_class(): void
    {
        // Regression for #9: a concrete test class that declares no test
        // methods of its own, inheriting them all from an abstract fixture,
        // must still replay. PHPUnit itself records the edge under the
        // *fixture's* file (Reflection::sourceLocationFor reflects the
        // method's declaring class), so the replay lookup has to resolve the
        // same way rather than reflecting the concrete subclass.
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");

        $method = 'test_it_works';
        $fixtureClass = 'TiaFixtureAbstract'.bin2hex(random_bytes(6));
        $this->repo->write(
            'tests/EmailQueueFixture.php',
            "<?php\n\nabstract class {$fixtureClass}\n{\n    public function {$method}(): void {}\n}\n",
        );
        require $this->repo->path().'/tests/EmailQueueFixture.php';

        $concreteClass = 'TiaFixtureConcrete'.bin2hex(random_bytes(6));
        $this->repo->write(
            'tests/FooTest.php',
            "<?php\n\nclass {$concreteClass} extends {$fixtureClass}\n{\n}\n",
        );
        require $this->repo->path().'/tests/FooTest.php';

        $sha = $this->repo->commit('add Foo + fixture + concrete subclass');

        $graph = new Graph($this->repo->path());
        // Edge keyed by the fixture file — matching what PHPUnit's own
        // TestMethodBuilder records for an inherited test method.
        $graph->link($this->repo->path().'/tests/EmailQueueFixture.php', $this->repo->path().'/src/Foo.php');
        $graph->setResult('main', $concreteClass.'::'.$method, TestStatus::success()->asInt(), '', 0.01, 1, 'tests/EmailQueueFixture.php');
        $graph->setFingerprint(Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', $sha);

        $changedFiles = new ChangedFiles($this->repo->path());
        $graph->setLastRunTree('main', $changedFiles->snapshotTree(['src/Foo.php', 'tests/EmailQueueFixture.php', 'tests/FooTest.php']));

        $state = new FileState(Storage::resolve($this->repo->path(), 'local'));
        $state->write(Storage::GRAPH_KEY, (string) $graph->encode());

        Tia::configure($this->repo->path(), 'local');

        $status = Tia::instance()->cachedStatusIfUnaffected($concreteClass, $method);

        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
    }

    #[Test]
    public function it_uses_a_configured_fallback_branch_for_replay(): void
    {
        [$class, $method, $sha] = $this->recordPassingTest(baselineBranch: 'develop');

        Tia::configure($this->repo->path(), 'local', fallbackBranch: 'develop');
        $tia = Tia::instance();

        $status = $tia->cachedStatusIfUnaffected($class, $method);

        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
        $this->assertSame(3, $tia->cachedAssertionCount($class, $method));
        $this->assertSame($sha, $tia->recordedAtSha());
    }

    /**
     * Same setup as the test above but for the tree, which is seeded stale —
     * so the recordedAtSha() assertion is what proves the develop baseline was
     * found at all (without it, "no baseline anywhere" would produce the same
     * null status and the test would pass for the wrong reason).
     */
    #[Test]
    public function it_uses_a_configured_fallback_tree_when_filtering_changes(): void
    {
        [$class, $method, $sha] = $this->recordPassingTest(
            baselineBranch: 'develop',
            lastRunTree: ['src/Foo.php' => 'stale-hash'],
        );

        Tia::configure($this->repo->path(), 'local', fallbackBranch: 'develop');
        $tia = Tia::instance();

        $this->assertSame($sha, $tia->recordedAtSha());
        $this->assertNull($tia->cachedStatusIfUnaffected($class, $method));
    }

    /**
     * Extension passes the raw XML parameter through untouched, so trimming
     * has to happen here or a padded value would never match a baseline key —
     * silently disabling the fallback with no way to distinguish that from
     * "the branch genuinely has no baseline".
     */
    #[Test]
    public function it_trims_a_configured_fallback_branch(): void
    {
        [$class, $method, $sha] = $this->recordPassingTest(baselineBranch: 'develop');

        Tia::configure($this->repo->path(), 'local', fallbackBranch: '  develop  ');
        $tia = Tia::instance();

        $status = $tia->cachedStatusIfUnaffected($class, $method);

        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
        $this->assertSame($sha, $tia->recordedAtSha());
    }

    /**
     * A blank value must degrade to the default branch rather than to a
     * fallback that can never match. Recorded on main and run from a branch
     * cut off it, so replaying proves the default was actually substituted —
     * on main itself the direct baseline hit would mask it.
     */
    #[Test]
    public function it_falls_back_to_the_default_branch_when_configured_blank(): void
    {
        [$class, $method, $sha] = $this->recordPassingTest(baselineBranch: 'main');
        $this->repo->run(['git', 'checkout', '-q', '-b', 'feature/x']);

        Tia::configure($this->repo->path(), 'local', fallbackBranch: '   ');
        $tia = Tia::instance();

        $status = $tia->cachedStatusIfUnaffected($class, $method);

        $this->assertNotNull($status);
        $this->assertTrue($status->isSuccess());
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

        $unknownClass = $this->defineFixtureClass('tests/UnknownTest.php', ['test_something']);

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
    public function it_passes_configured_resolvers_through_to_the_graphs_affected_computation(): void
    {
        // An unrelated, uncovered file with no known edge and no sibling in
        // the graph — core alone (direct edges + sibling-directory fallback,
        // §4.3) has no way to connect it to FooTest. Only a registered
        // Resolver (§4.3, §8) can mark FooTest affected here, proving the
        // extension point is actually wired end-to-end from configure()
        // through attemptBoot() into Graph::affected() — not just reachable
        // via Graph::setResolvers() in a unit test that bypasses Tia entirely.
        [$class, $method] = $this->recordPassingTest();
        $this->repo->write('database/migrations/2024_01_01_create_widgets_table.php', "<?php\n");

        $resolver = new class implements Resolver
        {
            public function resolve(string $projectRoot, string $changedRelativePath): array
            {
                if ($changedRelativePath === 'database/migrations/2024_01_01_create_widgets_table.php') {
                    return ['tests/FooTest.php'];
                }

                return [];
            }
        };

        Tia::configure($this->repo->path(), 'local', [$resolver]);

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

    #[Test]
    public function is_debug_reflects_the_env_var(): void
    {
        $this->assertFalse(Tia::isDebug());

        putenv('PHPUNIT_TIA_DEBUG=1');

        try {
            $this->assertTrue(Tia::isDebug());
        } finally {
            putenv('PHPUNIT_TIA_DEBUG');
        }
    }

    #[Test]
    public function debug_reason_reports_when_never_configured(): void
    {
        $this->assertSame(
            'TIA is not configured for this run',
            Tia::instance()->debugReason('AnyClass', 'any_method'),
        );
    }

    #[Test]
    public function debug_reason_reports_disabled_via_env(): void
    {
        [$class, $method] = $this->recordPassingTest();

        putenv('PHPUNIT_TIA=0');

        try {
            Tia::configure($this->repo->path(), 'local');
            $this->assertSame('disabled via PHPUNIT_TIA=0', Tia::instance()->debugReason($class, $method));
        } finally {
            putenv('PHPUNIT_TIA');
        }
    }

    #[Test]
    public function debug_reason_names_the_changed_source_file(): void
    {
        [$class, $method] = $this->recordPassingTest();

        // Same real-token change as it_does_not_replay_a_test_whose_source_file_changed().
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n    public int \$x = 1;\n}\n");

        Tia::configure($this->repo->path(), 'local');

        $this->assertSame('source changed: src/Foo.php', Tia::instance()->debugReason($class, $method));
    }

    #[Test]
    public function debug_reason_reports_a_test_unknown_to_the_graph(): void
    {
        $this->recordPassingTest();

        Tia::configure($this->repo->path(), 'local');

        $unknownClass = $this->defineFixtureClass('tests/UnknownTest.php', ['test_something']);

        $this->assertSame(
            'not yet recorded (new or never-run test)',
            Tia::instance()->debugReason($unknownClass, 'test_something'),
        );
    }

    #[Test]
    public function debug_reason_reports_a_non_success_cached_status(): void
    {
        [$class, $method] = $this->recordTest(TestStatus::failure('boom'));

        Tia::configure($this->repo->path(), 'local');

        $this->assertSame(
            'cached status was failure, only cached passes replay',
            Tia::instance()->debugReason($class, $method),
        );
    }

    #[Test]
    public function debug_reason_reports_no_cached_result_yet(): void
    {
        [$class] = $this->recordPassingTest();

        Tia::configure($this->repo->path(), 'local');

        $this->assertSame(
            'no cached result yet',
            Tia::instance()->debugReason($class, 'test_a_different_method_never_run'),
        );
    }

    #[Test]
    public function debug_reason_names_the_policy_that_would_force_a_rerun_of_a_known_unaffected_pass(): void
    {
        [$class, $method] = $this->recordPassingTest();

        Tia::configure($this->repo->path(), 'local');

        $this->assertSame(
            "a skip would violate this run's fail-on-skipped/display-skipped (or similar) policy",
            Tia::instance()->debugReason($class, $method),
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string} [className, methodName, sha]
     */
    private function recordPassingTest(
        ?string $sha = null,
        ?array $fingerprint = null,
        string $baselineBranch = 'main',
        ?array $lastRunTree = null,
    ): array {
        return $this->recordTest(TestStatus::success(), $sha, $fingerprint, $baselineBranch, $lastRunTree);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [className, methodName, sha]
     */
    private function recordTest(
        TestStatus $status,
        ?string $sha = null,
        ?array $fingerprint = null,
        string $baselineBranch = 'main',
        ?array $lastRunTree = null,
    ): array {
        $this->repo->write('src/Foo.php', "<?php\n\nclass Foo\n{\n}\n");
        $class = $this->defineFixtureClass('tests/FooTest.php', ['test_it_works', 'test_a_different_method_never_run']);
        $method = 'test_it_works';

        $recordedSha = $this->repo->commit('add Foo + FooTest');

        $graph = new Graph($this->repo->path());
        $graph->link($this->repo->path().'/tests/FooTest.php', $this->repo->path().'/src/Foo.php');
        $graph->setResult(
            $baselineBranch,
            $class.'::'.$method,
            $status->asInt(),
            $status->message(),
            0.01,
            3,
            'tests/FooTest.php',
        );
        $graph->setFingerprint($fingerprint ?? Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha($baselineBranch, $sha ?? $recordedSha);

        $changedFiles = new ChangedFiles($this->repo->path());
        $graph->setLastRunTree(
            $baselineBranch,
            $lastRunTree ?? $changedFiles->snapshotTree(['src/Foo.php', 'tests/FooTest.php']),
        );

        $state = new FileState(Storage::resolve($this->repo->path(), 'local'));
        $state->write(Storage::GRAPH_KEY, (string) $graph->encode());

        return [$class, $method, $sha ?? $recordedSha];
    }

    /**
     * @param  list<string>  $methods  Real method names to declare on the fixture class —
     *                                 Tia now resolves a test's file via ReflectionMethod
     *                                 (to match how PHPUnit itself records the edge), so
     *                                 callers must reflect an actual declared method, not
     *                                 just a class.
     */
    private function defineFixtureClass(string $relativePath, array $methods = ['test_it_works']): string
    {
        $class = 'TiaFixture'.bin2hex(random_bytes(6));
        $body = implode('', array_map(static fn (string $method) => "    public function {$method}(): void {}\n", $methods));
        $this->repo->write($relativePath, "<?php\n\nclass {$class}\n{\n{$body}}\n");
        require $this->repo->path().'/'.$relativePath;

        return $class;
    }
}
