<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests\Cli;

use JMac\Testing\PhpUnit\Tia\Cli\Plan;
use JMac\Testing\PhpUnit\Tia\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PlanTest extends TestCase
{
    #[Test]
    public function run_paths_carries_the_selection(): void
    {
        $plan = Plan::runPaths(['tests/AaTest.php'], 'one file affected');

        $this->assertSame(['tests/AaTest.php'], $plan->paths());
        $this->assertSame('one file affected', $plan->reason());
    }

    /**
     * The two empty outcomes mean opposite things and must never be conflated:
     * "nothing to do" exits clean, "cannot narrow" runs everything.
     */
    #[Test]
    public function run_nothing_is_not_run_all(): void
    {
        $nothing = Plan::runNothing('no affected test files');

        $this->assertTrue($nothing->isRunNothing());
        $this->assertFalse($nothing->isRunAll());
    }

    #[Test]
    public function run_all_is_not_run_nothing(): void
    {
        $all = Plan::runAll('no graph recorded yet');

        $this->assertTrue($all->isRunAll());
        $this->assertFalse($all->isRunNothing());
    }
}
