<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Traits;

/**
 * Drop into a base TestCase (§4.7). setUp() is the only overridable hook
 * PHPUnit exposes before the real test method runs (§3) — so this can only
 * ever skip a cached-passing test, never fake a pass.
 */
trait RunWithTia
{
    protected function setUp(): void
    {
        // TODO: milestone 4 — cachedStatusIfUnaffected() + shouldRerunStatus() policy check, then markTestSkipped()

        parent::setUp();
    }
}
