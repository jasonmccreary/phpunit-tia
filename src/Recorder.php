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
     * @param  array<string, array<int, array<int, int>|list<string>|null>>  $lineCoverage
     * @param  array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>  $results
     * @param  array<int, string>  $testIdByIndex  php-code-coverage >= 14.3 keys each covered line's
     *         hit map by an integer TestIndex (`<TestIndex => hitFlag>`); this translates that index
     *         back to the test id string. Pass the empty default for the legacy
     *         `<line => list<testIdString>>` shape, where the id is the value itself.
     * @return array<string, list<string>> test file (absolute) → list of source files (absolute)
     */
    public static function invert(array $lineCoverage, array $results, array $testIdByIndex = []): array
    {
        $edges = [];

        foreach ($lineCoverage as $sourceFile => $lines) {
            foreach ($lines as $perLine) {
                if ($perLine === null) {
                    continue;
                }

                foreach ($perLine as $key => $value) {
                    // Legacy php-code-coverage: `<line => list<testIdString>>` — the id is the value.
                    // php-code-coverage >= 14.3: `<line => <TestIndex => hitFlag>>` — the id is keyed
                    // by index, and the value is a hit flag, so translate the key via $testIdByIndex.
                    $testId = is_string($value) ? $value : ($testIdByIndex[$key] ?? null);

                    if ($testId === null) {
                        continue;
                    }

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
