<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\PreparationFailedSubscriber;

/**
 * A test whose setUp() (or another before-test method) fails an assertion
 * never reaches Prepared, so the failure PHPUnit reports next would be dropped
 * and the test's previous pass would survive the run. Naming the test here
 * lets the failure be recorded.
 */
final readonly class RecordTestPreparationFailed implements PreparationFailedSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(PreparationFailed $event): void
    {
        $test = $event->test();

        $this->results->testPrepared($test->id(), $test->file());
    }
}
