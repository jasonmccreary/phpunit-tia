<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\PreparationErrored;
use PHPUnit\Event\Test\PreparationErroredSubscriber;

/**
 * A test whose setUp() (or another before-test method) throws never reaches
 * Prepared, so the error PHPUnit reports next would be dropped and the test's
 * previous pass would survive the run. Naming the test here lets the error
 * be recorded.
 */
final readonly class RecordTestPreparationErrored implements PreparationErroredSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(PreparationErrored $event): void
    {
        $test = $event->test();

        $this->results->testPrepared($test->id(), $test->file());
    }
}
