<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Recorder;
use PHPUnit\Framework\Attributes\Test;

final class RecorderTest extends TestCase
{
    #[Test]
    public function it_inverts_line_coverage_into_test_file_to_source_files(): void
    {
        $lineCoverage = [
            '/app/src/Calculator.php' => [
                11 => ['Tests\\CalculatorTest::test_it_adds'],
                16 => ['Tests\\CalculatorTest::test_it_subtracts'],
            ],
        ];

        $results = [
            'Tests\\CalculatorTest::test_it_adds' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => '/app/tests/CalculatorTest.php'],
            'Tests\\CalculatorTest::test_it_subtracts' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => '/app/tests/CalculatorTest.php'],
        ];

        $edges = Recorder::invert($lineCoverage, $results);

        $this->assertSame(['/app/tests/CalculatorTest.php' => ['/app/src/Calculator.php']], $edges);
    }

    #[Test]
    public function it_deduplicates_a_source_file_covered_by_the_same_test_on_multiple_lines(): void
    {
        $lineCoverage = [
            '/app/src/Calculator.php' => [
                11 => ['Tests\\CalculatorTest::test_it_adds'],
                12 => ['Tests\\CalculatorTest::test_it_adds'],
            ],
        ];

        $results = [
            'Tests\\CalculatorTest::test_it_adds' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => '/app/tests/CalculatorTest.php'],
        ];

        $edges = Recorder::invert($lineCoverage, $results);

        $this->assertSame(['/app/tests/CalculatorTest.php'], array_keys($edges));
        $this->assertSame(['/app/src/Calculator.php'], $edges['/app/tests/CalculatorTest.php']);
    }

    #[Test]
    public function it_skips_lines_with_no_test_ids(): void
    {
        $lineCoverage = [
            '/app/src/Calculator.php' => [
                11 => null,
            ],
        ];

        $this->assertSame([], Recorder::invert($lineCoverage, []));
    }

    #[Test]
    public function it_skips_test_ids_with_no_known_file(): void
    {
        $lineCoverage = [
            '/app/src/Calculator.php' => [
                11 => ['Tests\\CalculatorTest::test_it_adds'],
            ],
        ];

        // No matching entry in $results, so the file for this test id is unknown.
        $this->assertSame([], Recorder::invert($lineCoverage, []));
    }

    #[Test]
    public function multiple_tests_covering_one_source_file_each_get_their_own_edge(): void
    {
        $lineCoverage = [
            '/app/src/Calculator.php' => [
                11 => ['Tests\\CalculatorTest::test_it_adds'],
                16 => ['Tests\\SubtractionTest::test_it_subtracts'],
            ],
        ];

        $results = [
            'Tests\\CalculatorTest::test_it_adds' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => '/app/tests/CalculatorTest.php'],
            'Tests\\SubtractionTest::test_it_subtracts' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => '/app/tests/SubtractionTest.php'],
        ];

        $edges = Recorder::invert($lineCoverage, $results);

        $this->assertSame(['/app/src/Calculator.php'], $edges['/app/tests/CalculatorTest.php']);
        $this->assertSame(['/app/src/Calculator.php'], $edges['/app/tests/SubtractionTest.php']);
    }

    #[Test]
    public function it_inverts_index_keyed_line_coverage_from_php_code_coverage_14_3(): void
    {
        // php-code-coverage >= 14.3 interns test ids: each covered line maps
        // <TestIndex => hitFlag> instead of a list of test-id strings, and the
        // index resolves to a test id via ProcessedCodeCoverageData::testIds().
        $lineCoverage = [
            '/app/src/Calculator.php' => [
                11 => [0 => 1],
                16 => [1 => 1],
            ],
        ];

        $testIdByIndex = [
            0 => 'Tests\\CalculatorTest::test_it_adds',
            1 => 'Tests\\CalculatorTest::test_it_subtracts',
        ];

        $results = [
            'Tests\\CalculatorTest::test_it_adds' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => '/app/tests/CalculatorTest.php'],
            'Tests\\CalculatorTest::test_it_subtracts' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => '/app/tests/CalculatorTest.php'],
        ];

        $edges = Recorder::invert($lineCoverage, $results, $testIdByIndex);

        $this->assertSame(['/app/tests/CalculatorTest.php' => ['/app/src/Calculator.php']], $edges);
    }

    #[Test]
    public function it_skips_an_index_keyed_hit_with_no_known_test_index(): void
    {
        // The hit flag value (1) must never be mistaken for a test id, and an
        // index absent from the map is skipped rather than producing a false edge.
        $lineCoverage = [
            '/app/src/Calculator.php' => [
                11 => [7 => 1],
            ],
        ];

        $this->assertSame([], Recorder::invert($lineCoverage, [], []));
    }
}
