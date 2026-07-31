<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

final readonly class RecordTestPrepared implements PreparedSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(Prepared $event): void
    {
        $test = $event->test();

        $this->results->testPrepared($test->id(), $test->file());
    }
}
