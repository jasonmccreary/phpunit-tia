<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ChangedFiles;
use JMac\Testing\PhpUnit\Tia\FileState;
use JMac\Testing\PhpUnit\Tia\Fingerprint;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\GraphWriter;
use JMac\Testing\PhpUnit\Tia\Storage;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestStatus\TestStatus;

/**
 * A narrowed run keeps the baseline where it was, so what it recorded for a
 * test the changes affected describes code that differs from that baseline.
 * Those results and edges must not reach the graph unless the run was
 * authoritative.
 */
final class GraphWriterTest extends TestCase
{
    private const AFFECTED = 'AffectedTest::test_it_runs';

    private const OTHER = 'OtherTest::test_it_runs';

    private TempGitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = TempGitRepository::create();

        foreach (['src/Old.php', 'src/New.php', 'tests/AffectedTest.php', 'tests/OtherTest.php'] as $file) {
            $this->repo->write($file, "<?php\n");
        }

        $this->repo->commit('seed');

        $graph = new Graph($this->repo->path());
        $graph->replaceEdges([
            $this->path('tests/AffectedTest.php') => [$this->path('src/Old.php')],
            $this->path('tests/OtherTest.php') => [$this->path('src/Old.php')],
        ]);
        $graph->setResult('main', self::AFFECTED, TestStatus::failure()->asInt(), 'baseline failure', 0.01, 1, 'tests/AffectedTest.php');
        $graph->setResult('main', self::OTHER, TestStatus::success()->asInt(), '', 0.01, 1, 'tests/OtherTest.php');
        $graph->setFingerprint(Fingerprint::compute($this->repo->path()));
        $graph->setRecordedAtSha('main', (new ChangedFiles($this->repo->path()))->currentSha());

        $this->state()->write(Storage::GRAPH_KEY, (string) $graph->encode());
    }

    protected function tearDown(): void
    {
        if ($this->skippedByTia()) {
            return;
        }

        if (isset($this->repo)) {
            $this->repo->cleanup();
        }

        parent::tearDown();
    }

    #[Test]
    public function it_keeps_the_baseline_of_an_affected_test_when_the_run_is_not_authoritative(): void
    {
        $this->write(authoritative: false);

        $graph = $this->persistedGraph();

        $this->assertFalse($graph->getResult('main', self::AFFECTED)?->isSuccess(), 'A pass earned on changed code must not replace the baseline failure.');
        $this->assertSame(1, $graph->getAssertions('main', self::AFFECTED));
        $this->assertNotContains('tests/AffectedTest.php', $graph->testsLinkedTo($this->path('src/New.php')), 'An edge recorded against changed code must not reach the baseline.');
        $this->assertContains('tests/AffectedTest.php', $graph->testsLinkedTo($this->path('src/Old.php')));
    }

    #[Test]
    public function it_still_records_a_test_that_was_not_affected_when_the_run_is_not_authoritative(): void
    {
        $this->write(authoritative: false);

        $graph = $this->persistedGraph();

        $this->assertSame(7, $graph->getAssertions('main', self::OTHER));
        $this->assertSame(['tests/OtherTest.php'], $graph->testsLinkedTo($this->path('src/New.php')));
    }

    #[Test]
    public function it_records_an_affected_test_when_the_run_is_authoritative(): void
    {
        $this->write(authoritative: true);

        $graph = $this->persistedGraph();

        $this->assertTrue($graph->getResult('main', self::AFFECTED)?->isSuccess());
        $this->assertContains('tests/AffectedTest.php', $graph->testsLinkedTo($this->path('src/New.php')));
    }

    #[Test]
    public function it_records_everything_when_no_test_file_was_affected(): void
    {
        $this->write(authoritative: false, affected: []);

        $graph = $this->persistedGraph();

        $this->assertTrue($graph->getResult('main', self::AFFECTED)?->isSuccess());
        $this->assertContains('tests/AffectedTest.php', $graph->testsLinkedTo($this->path('src/New.php')));
    }

    /**
     * @param  list<string>  $affected
     */
    private function write(bool $authoritative, array $affected = ['tests/AffectedTest.php']): void
    {
        $result = static fn (string $file, int $assertions): array => [
            'status' => TestStatus::success()->asInt(),
            'message' => '',
            'time' => 0.02,
            'assertions' => $assertions,
            'file' => $file,
        ];

        (new GraphWriter($this->repo->path(), 'local'))->write(
            [
                $this->path('tests/AffectedTest.php') => [$this->path('src/New.php')],
                $this->path('tests/OtherTest.php') => [$this->path('src/New.php')],
            ],
            [
                self::AFFECTED => $result($this->path('tests/AffectedTest.php'), 3),
                self::OTHER => $result($this->path('tests/OtherTest.php'), 7),
            ],
            $authoritative,
            $affected,
        );
    }

    private function path(string $relative): string
    {
        return $this->repo->path().'/'.$relative;
    }

    private function persistedGraph(): Graph
    {
        $raw = $this->state()->read(Storage::GRAPH_KEY);

        $this->assertNotNull($raw);

        $graph = Graph::decode($raw, $this->repo->path());

        $this->assertNotNull($graph);

        return $graph;
    }

    private function state(): FileState
    {
        return new FileState(Storage::resolve($this->repo->path(), 'local'));
    }
}
