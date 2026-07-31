<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final readonly class RecordTestFinished implements FinishedSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(Finished $event): void
    {
        $this->results->recordAssertions($event->test()->id(), $event->numberOfAssertionsPerformed());
        $this->results->finishTest();
    }
}
