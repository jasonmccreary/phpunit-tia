<?php

declare(strict_types=1);

namespace Tests;

use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RunWithTia;
}
