<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

/**
 * Cache-invalidation fingerprint (§4.4): composer.lock + phpunit.xml +
 * PHP major version. structuralMatches() forces a full graph rebuild;
 * environmentalDrift() keeps edges but drops cached pass/fail results.
 */
final class Fingerprint
{
    // TODO: milestone 2
}
