<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Framework\Attributes\Test;

final class ResultCollectorTest extends TestCase
{
    #[Test]
    public function it_records_a_passed_test_with_timing_and_file(): void
    {
        $collector = new ResultCollector;
        $collector->testPrepared('Tests\\FooTest::it_works', 'tests/FooTest.php');
        $collector->testPassed();

        $result = $collector->all()['Tests\\FooTest::it_works'];

        $this->assertSame(0, $result['status']);
        $this->assertSame('', $result['message']);
        $this->assertSame('tests/FooTest.php', $result['file']);
        $this->assertGreaterThanOrEqual(0.0, $result['time']);
    }

    #[Test]
    public function it_records_a_failure_message(): void
    {
        $collector = new ResultCollector;
        $collector->testPrepared('Tests\\FooTest::it_fails');
        $collector->testFailed('expected true, got false');

        $result = $collector->all()['Tests\\FooTest::it_fails'];

        $this->assertSame(7, $result['status']);
        $this->assertSame('expected true, got false', $result['message']);
        $this->assertArrayNotHasKey('file', $result);
    }

    #[Test]
    public function record_assertions_attaches_the_count_after_the_fact(): void
    {
        $collector = new ResultCollector;
        $collector->testPrepared('Tests\\FooTest::it_works');
        $collector->testPassed();
        $collector->recordAssertions('Tests\\FooTest::it_works', 4);

        $this->assertSame(4, $collector->all()['Tests\\FooTest::it_works']['assertions']);
    }

    #[Test]
    public function record_assertions_is_a_no_op_for_an_unknown_test_id(): void
    {
        $collector = new ResultCollector;
        $collector->recordAssertions('Tests\\Nope::nope', 4);

        $this->assertSame([], $collector->all());
    }

    #[Test]
    public function outcome_calls_before_any_prepared_test_are_ignored(): void
    {
        $collector = new ResultCollector;
        $collector->testPassed();

        $this->assertSame([], $collector->all());
    }

    #[Test]
    public function finish_test_clears_current_test_state(): void
    {
        $collector = new ResultCollector;
        $collector->testPrepared('Tests\\FooTest::it_works');
        $collector->finishTest();
        $collector->testPassed();

        // testPassed() after finishTest() has no "current" test, so it's a no-op.
        $this->assertSame([], $collector->all());
    }

    #[Test]
    public function reset_clears_everything(): void
    {
        $collector = new ResultCollector;
        $collector->testPrepared('Tests\\FooTest::it_works');
        $collector->testPassed();
        $collector->reset();

        $this->assertSame([], $collector->all());
    }

    #[Test]
    public function a_second_prepared_call_overwrites_the_current_test(): void
    {
        $collector = new ResultCollector;
        $collector->testPrepared('Tests\\FooTest::a');
        $collector->testPrepared('Tests\\FooTest::b');
        $collector->testPassed();

        $this->assertArrayNotHasKey('Tests\\FooTest::a', $collector->all());
        $this->assertArrayHasKey('Tests\\FooTest::b', $collector->all());
    }
}
