<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestConsideredRisky;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestErrored;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestFailed;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestFinished;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestMarkedIncomplete;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestPassed;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestPrepared;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestSkipped;
use JMac\Testing\PhpUnit\Tia\Subscribers\WriteGraph;
use PHPUnit\Runner\Extension\Extension as ExtensionContract;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Configuration\Configuration;

final class Extension implements ExtensionContract
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (! Tia::isEnabled()) {
            fwrite(STDERR, "phpunit-tia: disabled via PHPUNIT_TIA=0.\n");

            return;
        }

        $projectRoot = $this->projectRoot($configuration);
        $storageMode = $this->storageMode($parameters);
        $resolvers = Config::loadResolvers($projectRoot);

        // Configure the replay side unconditionally, before the driver check
        // below: reading an already-recorded graph and skipping unaffected
        // tests needs no coverage driver at all, only recording new edges
        // does. This lets RunWithTia keep working on a machine that lost its
        // driver after the graph was written elsewhere (e.g. CI vs. local).
        Tia::configure($projectRoot, $storageMode, $resolvers);

        if (! $this->coverageDriverAvailable()) {
            fwrite(STDERR, "phpunit-tia: no coverage driver (pcov/xdebug) available — recording disabled for this run.\n");

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

        $results = new ResultCollector;

        $facade->registerSubscribers(
            new RecordTestPrepared($results),
            new RecordTestPassed($results),
            new RecordTestFailed($results),
            new RecordTestErrored($results),
            new RecordTestSkipped($results),
            new RecordTestMarkedIncomplete($results),
            new RecordTestConsideredRisky($results),
            new RecordTestFinished($results),
            new WriteGraph($projectRoot, $results, $storageMode),
        );
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

    /**
     * The directory containing phpunit.xml is the natural project root for
     * every path-relative operation this package does (git plumbing,
     * fingerprinting, edge resolution) — falls back to the working
     * directory only if PHPUnit was somehow run without a config file.
     */
    private function projectRoot(Configuration $configuration): string
    {
        if ($configuration->hasConfigurationFile()) {
            return dirname($configuration->configurationFile());
        }

        return getcwd() ?: '.';
    }

    /**
     * <parameter name="storage" value="global|local"/> (§7). Defaults to
     * global — outside the repo, keyed by git remote (Storage::resolve()) —
     * since that's the one consumers get for free with zero .gitignore work.
     */
    private function storageMode(ParameterCollection $parameters): string
    {
        if ($parameters->has('storage') && $parameters->get('storage') === 'local') {
            return 'local';
        }

        return 'global';
    }
}
