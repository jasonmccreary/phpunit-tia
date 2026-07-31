<?php

declare(strict_types=1);

namespace Tests;

use App\Widget;

final class WidgetTest extends TestCase
{
    public function test_it_has_a_name(): void
    {
        $this->assertSame('widget', (new Widget)->name());
    }
}
