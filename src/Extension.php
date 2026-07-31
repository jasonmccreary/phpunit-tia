<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use JMac\Testing\PhpUnit\Tia\Subscribers\DumpCoverageShape;
use PHPUnit\Runner\Extension\Extension as ExtensionContract;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Configuration\Configuration;

final class Extension implements ExtensionContract
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (! $this->coverageDriverAvailable()) {
            fwrite(STDERR, "phpunit-tia: no coverage driver (pcov/xdebug) available — TIA disabled for this run.\n");

            return;
        }

        // Verified against PHPUnit 13.2.6 (TIA.md §4.1, §6): CodeCoverage::init()
        // calls CodeCoverageFilterRegistry::init($configuration) without forwarding
        // force=true when only an extension (not a <coverage><report> target)
        // requires collection, leaving the filter null. Its own get() then hits
        // assert($this->filter !== null) and crashes. Pre-populate it ourselves so
        // consumers don't have to add a throwaway coverage report to their config.
        CodeCoverageFilterRegistry::instance()->init($configuration, true);

        $facade->requireCodeCoverageCollection();

        // Milestone 1 validation only (§10) — dumps lineCoverage()'s shape to
        // confirm the piggyback approach works before Recorder/Graph exist.
        $facade->registerSubscriber(new DumpCoverageShape(
            getcwd().'/.phpunit-tia/coverage-shape.json',
        ));
    }

    /**
     * Mirrors Pest's verified driver detection (pest/src/Plugins/Tia/Recorder.php)
     * rather than a blunt extension_loaded() check — pcov can be loaded but
     * disabled via ini, and xdebug can be loaded in a mode without coverage.
     */
    private function coverageDriverAvailable(): bool
    {
        if (function_exists('pcov\\start') && filter_var((string) ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        if (function_exists('xdebug_info')) {
            $modes = xdebug_info('mode');

            return is_array($modes) && in_array('coverage', $modes, true);
        }

        return false;
    }
}
