<?php

declare(strict_types=1);

namespace Tests;

use App\Calculator;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    public function test_it_adds(): void
    {
        $this->assertSame(4, (new Calculator())->add(2, 2));
    }

    public function test_it_subtracts(): void
    {
        $this->assertSame(1, (new Calculator())->subtract(3, 2));
    }
}
