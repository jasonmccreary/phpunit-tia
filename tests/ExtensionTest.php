<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Extension;
use PHPUnit\Framework\TestCase;

final class ExtensionTest extends TestCase
{
    public function test_it_implements_the_phpunit_extension_contract(): void
    {
        $this->assertInstanceOf(\PHPUnit\Runner\Extension\Extension::class, new Extension);
    }
}
