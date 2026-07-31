<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

final readonly class RecordTestFailed implements FailedSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(Failed $event): void
    {
        $this->results->testFailed($event->throwable()->message());
    }
}
