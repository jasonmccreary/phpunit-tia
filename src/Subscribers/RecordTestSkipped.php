<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

final readonly class RecordTestSkipped implements SkippedSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(Skipped $event): void
    {
        $this->results->testSkipped($event->message());
    }
}
