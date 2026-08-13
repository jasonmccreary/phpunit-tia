<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use JMac\Testing\PhpUnit\Tia\RunScope;
use PHPUnit\Event\TestRunner\ExecutionAborted;
use PHPUnit\Event\TestRunner\ExecutionAbortedSubscriber;

final readonly class RecordExecutionAborted implements ExecutionAbortedSubscriber
{
    public function __construct(private RunScope $scope) {}

    public function notify(ExecutionAborted $event): void
    {
        $this->scope->abort();
    }
}
