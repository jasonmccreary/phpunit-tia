<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Extension;
use ReflectionMethod;

final class ExtensionTest extends TestCase
{
    public function test_it_implements_the_phpunit_extension_contract(): void
    {
        $this->assertInstanceOf(\PHPUnit\Runner\Extension\Extension::class, new Extension);
    }

    public function test_it_detects_paratest_via_its_worker_environment_variable(): void
    {
        $method = new ReflectionMethod(Extension::class, 'runningUnderParaTest');

        putenv('PARATEST=1');

        try {
            $this->assertTrue($method->invoke(new Extension));
        } finally {
            putenv('PARATEST');
        }

        $this->assertFalse($method->invoke(new Extension));
    }
}
