<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber;

final readonly class RecordTestConsideredRisky implements ConsideredRiskySubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(ConsideredRisky $event): void
    {
        $this->results->testRisky($event->message());
    }
}
