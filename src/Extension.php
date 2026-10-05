<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use JMac\Testing\PhpUnit\Tia\Subscribers\RecordExecutionAborted;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestConsideredRisky;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestErrored;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestFailed;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestFinished;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestMarkedIncomplete;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestPassed;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestPrepared;
use JMac\Testing\PhpUnit\Tia\Subscribers\RecordTestSkipped;
use JMac\Testing\PhpUnit\Tia\Subscribers\WarnCoversTargeting;
use JMac\Testing\PhpUnit\Tia\Subscribers\WriteGraph;
use PHPUnit\Framework\TestStatus\TestStatus;
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
            fwrite(STDERR, "phpunit-tia: inactive: disabled via PHPUNIT_TIA=0.\n");

            return;
        }

        $projectRoot = $this->projectRoot($configuration);
        $storageMode = $this->storageMode($parameters);
        $fallbackBranch = $this->fallbackBranch($parameters);
        $resolvers = Config::loadResolvers($projectRoot);

        // Configure the replay side unconditionally, before the driver check
        // below: reading an already-recorded graph and skipping unaffected
        // tests needs no coverage driver at all, only recording new edges
        // does. This lets RunWithTia keep working on a machine that lost its
        // driver after the graph was written elsewhere (e.g. CI vs. local).
        Tia::configure($projectRoot, $storageMode, $resolvers, $fallbackBranch);

        // Each ParaTest worker bootstraps its own PHPUnit, so the summary
        // would repeat once per worker.
        if (! ParallelRun::isWorker()) {
            fwrite(STDERR, 'phpunit-tia: '.$this->summary().".\n");
        }

        // The parent runs no tests of its own, so it merges the workers' shards
        // whether or not it has a coverage driver; the workers need one.
        if (ParallelRun::isParent()) {
            ParallelRun::coordinate($projectRoot, $storageMode);

            return;
        }

        if (! $this->coverageDriverAvailable()) {
            fwrite(STDERR, "phpunit-tia: no coverage driver (pcov/xdebug) available — recording disabled for this run.\n");

            return;
        }

        $parallelRun = null;

        if (ParallelRun::isWorker()) {
            $parallelRun = ParallelRun::join($projectRoot, $storageMode);

            if ($parallelRun === null) {
                // Every worker lands here, so only the first one says so.
                $token = getenv('TEST_TOKEN');

                if ($token === false || $token === '1') {
                    fwrite(STDERR, "phpunit-tia: running under ParaTest without a coordinating parent process, recording disabled to avoid a corrupted baseline.\n");
                }

                return;
            }
        }

        // Verified against PHPUnit 13.2.6 (docs/decisions.md): CodeCoverage::init()
        // calls CodeCoverageFilterRegistry::init($configuration) without forwarding
        // force=true when only an extension (not a <coverage><report> target)
        // requires collection, leaving the filter null. Its own get() then hits
        // assert($this->filter !== null) and crashes. Pre-populate it ourselves so
        // consumers don't have to add a throwaway coverage report to their config.
        CodeCoverageFilterRegistry::instance()->init($configuration, true);

        $facade->requireCodeCoverageCollection();

        $results = new ResultCollector;
        $scope = new RunScope;

        Tia::recordInto($results);

        $facade->registerSubscribers(
            new RecordTestPrepared($results),
            new RecordTestPassed($results),
            new RecordTestFailed($results),
            new RecordTestErrored($results),
            new RecordTestSkipped($results),
            new RecordTestMarkedIncomplete($results),
            new RecordTestConsideredRisky($results),
            new RecordTestFinished($results),
            new RecordExecutionAborted($scope),
            new WarnCoversTargeting,
            new WriteGraph($projectRoot, $results, $storageMode, $scope, $parallelRun),
        );
    }

    /**
     * RunWithTia never skips when this run's configuration would fail on, or
     * display details for, a skip — every test runs, so an affected count
     * would read as if the rest were skipped.
     */
    private function summary(): string
    {
        $tia = Tia::instance();

        if ($tia->isActive() && $tia->shouldRerunStatus(TestStatus::skipped())) {
            return "inactive: a skip would violate this run's fail-on-skipped/display-skipped policy — every test runs";
        }

        return $tia->summary();
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

    /**
     * <parameter name="fallback-branch" value="develop"/> — the branch whose
     * baseline is read when the current branch has none of its own. Defaults
     * to Graph::DEFAULT_FALLBACK_BRANCH.
     *
     * Passed through verbatim — trimming and the empty => default
     * substitution belong to Tia::configure(), the single choke point every
     * entry point already goes through.
     */
    private function fallbackBranch(ParameterCollection $parameters): string
    {
        if (! $parameters->has('fallback-branch')) {
            return Graph::DEFAULT_FALLBACK_BRANCH;
        }

        return $parameters->get('fallback-branch');
    }
}
