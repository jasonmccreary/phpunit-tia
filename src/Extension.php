<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use PHPUnit\Runner\Extension\Extension as ExtensionContract;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class Extension implements ExtensionContract
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        // TODO: milestone 1 — requireCodeCoverageCollection() + register subscribers (§4.1, §4.6)
    }
}
