<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use PHPUnit\Framework\TestStatus\TestStatus;

/**
 * Plain in-memory collector for per-test status/message/time/assertions
 * (§4.6), keyed by test ID. Ported from Pest's ResultCollector.php — zero
 * PHPUnit-event coupling, driven entirely by the Subscribers/* classes that
 * translate real PHPUnit\Event objects into these method calls.
 */
final class ResultCollector
{
    /**
     * @var array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>
     */
    private array $results = [];

    private ?string $currentTestId = null;

    private ?string $currentTestFile = null;

    private ?float $startTime = null;

    public function testPrepared(string $testId, ?string $testFile = null): void
    {
        $this->currentTestId = $testId;
        $this->currentTestFile = $testFile;
        $this->startTime = microtime(true);
    }

    public function testPassed(): void
    {
        $this->record(TestStatus::success());
    }

    public function testFailed(string $message): void
    {
        $this->record(TestStatus::failure($message));
    }

    public function testErrored(string $message): void
    {
        $this->record(TestStatus::error($message));
    }

    public function testSkipped(string $message): void
    {
        $this->record(TestStatus::skipped($message));
    }

    public function testIncomplete(string $message): void
    {
        $this->record(TestStatus::incomplete($message));
    }

    public function testRisky(string $message): void
    {
        $this->record(TestStatus::risky($message));
    }

    /**
     * @return array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>
     */
    public function all(): array
    {
        return $this->results;
    }

    public function recordAssertions(string $testId, int $assertions): void
    {
        if (isset($this->results[$testId])) {
            $this->results[$testId]['assertions'] = $assertions;
        }
    }

    public function reset(): void
    {
        $this->results = [];
        $this->currentTestId = null;
        $this->currentTestFile = null;
        $this->startTime = null;
    }

    public function finishTest(): void
    {
        $this->currentTestId = null;
        $this->currentTestFile = null;
        $this->startTime = null;
    }

    private function record(TestStatus $status): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        $time = $this->startTime !== null
            ? round(microtime(true) - $this->startTime, 3)
            : 0.0;

        $existing = $this->results[$this->currentTestId] ?? null;

        $this->results[$this->currentTestId] = [
            'status' => $status->asInt(),
            'message' => $status->message(),
            'time' => $time,
            'assertions' => $existing['assertions'] ?? 0,
        ];

        if ($this->currentTestFile !== null) {
            $this->results[$this->currentTestId]['file'] = $this->currentTestFile;
        }
    }
}
