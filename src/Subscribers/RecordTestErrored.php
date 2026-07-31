<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

final readonly class RecordTestErrored implements ErroredSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(Errored $event): void
    {
        $this->results->testErrored($event->throwable()->message());
    }
}
