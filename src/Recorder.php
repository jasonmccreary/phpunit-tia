<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

/**
 * Piggybacks on PHPUnit's own coverage collection (§4.1). Reads
 * PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->getData()->lineCoverage()
 * once at the end of the run and inverts it into test file → source file edges.
 */
final class Recorder
{
    // TODO: milestone 1/3
}
