<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

/**
 * Whether this run was cut short mid-suite (`--stop-on-failure`/`--stop-on-error`/etc.,
 * or Ctrl-C) rather than completing everything it started. Set by
 * Subscribers\RecordExecutionAborted from PHPUnit\Event\TestRunner\ExecutionAborted,
 * which PHPUnit's TestSuite::run() always emits before ExecutionFinished in that case —
 * so Subscribers\WriteGraph::isPartialRun() can tell the difference between "nothing
 * left to run" and "stopped before finishing" when deciding whether this run is
 * authoritative enough to advance the baseline.
 */
final class RunScope
{
    private bool $aborted = false;

    public function abort(): void
    {
        $this->aborted = true;
    }

    public function wasAborted(): bool
    {
        return $this->aborted;
    }
}
