<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\ResultCollector;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;

final readonly class RecordTestPassed implements PassedSubscriber
{
    public function __construct(private ResultCollector $results) {}

    public function notify(Passed $event): void
    {
        $this->results->testPassed();
    }
}
