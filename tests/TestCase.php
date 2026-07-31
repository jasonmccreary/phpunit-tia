<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Dogfoods TIA on this package's own suite (registered in phpunit.xml.dist).
 * TiaTest/RunWithTiaTest deliberately stay on the base PHPUnit TestCase
 * instead of this one — they manipulate Tia's process-wide singleton
 * directly to test it in isolation, and re-arm it for the real project in
 * their own tearDown() afterward (see the comments there).
 */
abstract class TestCase extends BaseTestCase
{
    use RunWithTia;
}
