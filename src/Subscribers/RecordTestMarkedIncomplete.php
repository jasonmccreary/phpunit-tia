<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;

final readonly class RecordTestMarkedIncomplete implements MarkedIncompleteSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(MarkedIncomplete $event): void
    {
        $this->results->testIncomplete($event->throwable()->message());
    }
}
