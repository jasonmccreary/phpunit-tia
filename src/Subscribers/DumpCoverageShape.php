<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\CodeCoverage;

/**
 * Milestone 1 validation (TIA.md §4.1, §10): confirms that
 * Facade::requireCodeCoverageCollection() plus
 * CodeCoverage::instance()->codeCoverage()->getData()->lineCoverage() actually
 * produces the array<sourceFile, array<line, list<testId>|null>> shape the
 * design doc assumes, and that a missing coverage driver degrades to an
 * inactive CodeCoverage instance instead of hard-failing the run.
 *
 * Throwaway — superseded by Recorder once milestone 3 builds the real
 * edge-inversion logic on top of this same read-back call.
 */
final readonly class DumpCoverageShape implements ExecutionFinishedSubscriber
{
    public function __construct(private string $destination) {}

    public function notify(ExecutionFinished $event): void
    {
        $coverage = CodeCoverage::instance();

        if (! $coverage->isActive()) {
            $this->write([
                'active' => false,
                'reason' => 'CodeCoverage::instance()->isActive() is false — no driver available or Selector threw',
            ]);

            return;
        }

        $lineCoverage = $coverage->codeCoverage()->getData()->lineCoverage();

        $this->write([
            'active' => true,
            'driver' => $coverage->driverNameAndVersion(),
            'file_count' => count($lineCoverage),
            'sample' => array_slice($lineCoverage, 0, 3, preserve_keys: true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(array $payload): void
    {
        $directory = dirname($this->destination);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents(
            $this->destination,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
        );
    }
}
