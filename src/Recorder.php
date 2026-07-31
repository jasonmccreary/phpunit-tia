<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

/**
 * Piggybacks on PHPUnit's own coverage collection (§4.1). Inverts
 * PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->getData()->lineCoverage()'s
 * shape — array<sourceFile, array<line, list<testId>|null>> — into test file
 * → source file edges. Reading the CodeCoverage singleton itself is left to
 * the caller (Subscribers\WriteGraph) so this class stays a pure function,
 * testable with a fabricated lineCoverage array instead of a real coverage
 * session.
 *
 * Resolves each test ID back to its file using the already-known file from
 * ResultCollector's tracked results (populated from the real PHPUnit\Event\Test\Prepared
 * event) rather than reflecting each class: real PHPUnit tests are actual
 * classes/methods PHPUnit already told us the file for, so this is simpler
 * than Pest's version, which has to unwind Pest's dynamically-generated
 * __filename static property.
 */
final class Recorder
{
    /**
     * @param  array<string, array<int, list<string>|null>>  $lineCoverage
     * @param  array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>  $results
     * @return array<string, list<string>> test file (absolute) → list of source files (absolute)
     */
    public static function invert(array $lineCoverage, array $results): array
    {
        $edges = [];

        foreach ($lineCoverage as $sourceFile => $lines) {
            foreach ($lines as $testIds) {
                if ($testIds === null) {
                    continue;
                }

                foreach ($testIds as $testId) {
                    $testFile = $results[$testId]['file'] ?? null;

                    if ($testFile === null) {
                        continue;
                    }

                    $edges[$testFile][$sourceFile] = true;
                }
            }
        }

        $out = [];

        foreach ($edges as $testFile => $sources) {
            $out[$testFile] = array_keys($sources);
        }

        return $out;
    }
}
