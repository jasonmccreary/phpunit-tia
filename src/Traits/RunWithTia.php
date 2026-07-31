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
    private bool $skippedByTia = false;

    protected function setUp(): void
    {
        $tia = Tia::instance();
        $method = $this->name().($this->dataName() !== '' ? '#'.$this->dataName() : '');
        $status = $tia->cachedStatusIfUnaffected(static::class, $method);

        if ($status !== null && ! $tia->shouldRerunStatus(TestStatus::skipped())) {
            $this->skippedByTia = true;
            $this->addToAssertionCount($tia->cachedAssertionCount(static::class, $method));
            $this->markTestSkipped(sprintf(
                'TIA: unaffected since %s, last run passed',
                $tia->recordedAtSha() ?? 'unknown',
            ));
        }

        parent::setUp();
    }

    /**
     * A skipped test never reaches `parent::setUp()` (§ above) — anything
     * that hook would normally provide (an app container, a DB connection,
     * a resolved facade root, ...) is simply absent. tearDown() still runs
     * unconditionally (PHPUnit always invokes 'after' hooks once a test has
     * started; see docs/decisions.md), so any custom tearDown() that
     * depends on setUp() having actually run must guard itself with this.
     * A tearDown() that throws instead turns the skip into a failure/error
     * (PHPUnit demotes it — see docs/decisions.md).
     */
    protected function skippedByTia(): bool
    {
        return $this->skippedByTia;
    }
}
