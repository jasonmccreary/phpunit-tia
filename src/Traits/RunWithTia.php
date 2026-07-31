<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Traits;

use JMac\Testing\PhpUnit\Tia\Tia;
use PHPUnit\Framework\TestStatus\TestStatus;

/**
 * Drop into a base TestCase (§4.7). setUp() is the only overridable hook
 * PHPUnit exposes before the real test method runs (§3) — so this can only
 * ever skip a cached-passing test, never fake a pass.
 */
trait RunWithTia
{
    protected function setUp(): void
    {
        $tia = Tia::instance();
        $status = $tia->cachedStatusIfUnaffected(static::class, $this->name());

        if ($status !== null && ! $tia->shouldRerunStatus(TestStatus::skipped())) {
            $this->addToAssertionCount($tia->cachedAssertionCount(static::class, $this->name()));
            $this->markTestSkipped(sprintf(
                'TIA: unaffected since %s, last run passed',
                $tia->recordedAtSha() ?? 'unknown',
            ));
        }

        parent::setUp();
    }
}
