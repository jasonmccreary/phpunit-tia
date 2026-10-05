<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use Throwable;

/**
 * Whether this run selects less than the full configured suite. Shared by the
 * single-process writer and the ParaTest parent, which merges worker shards.
 */
final class SuiteSelection
{
    /**
     * Narrowed means `--filter`, `--group`, `--testsuite`, or an explicit path
     * argument, as opposed to everything phpunit.xml selects on its own.
     *
     * The groups and default test suite selected by phpunit.xml itself are that
     * full configured suite, not a narrowing: PHPUnit merges them into the same
     * Configuration as their command-line counterparts, so they are compared to
     * what the XML file selects on its own rather than just tested for presence.
     * Otherwise a project that permanently excludes a group (slow, external...)
     * never records a baseline at all.
     */
    public static function isNarrowed(): bool
    {
        $configuration = Registry::get();

        return $configuration->hasFilter()
            || $configuration->hasExcludeFilter()
            || $configuration->hasTestIdFilter()
            || $configuration->hasTestIdFilterFile()
            || self::groupsDifferFromXmlConfiguration($configuration)
            || ! self::sameSet($configuration->includeTestSuites(), self::defaultTestSuites($configuration))
            || $configuration->excludeTestSuites() !== []
            || $configuration->hasCliArguments();
    }

    /**
     * Mirrors how PHPUnit's Merger derives the groups when the command line sets
     * none: the XML `<groups>` include list, and its exclude list minus any group
     * also included. If the XML file can't be read back, assume a narrowing: the
     * safe direction, since it only holds the baseline back.
     */
    private static function groupsDifferFromXmlConfiguration(Configuration $configuration): bool
    {
        try {
            $xmlGroups = $configuration->hasConfigurationFile()
                // PHPUnit 13.4 gave the loader an Emitter; earlier versions ignore the argument.
                ? (new Loader(EventFacade::emitter()))->load($configuration->configurationFile())->groups()
                : null;
        } catch (Throwable) {
            return true;
        }

        $include = $xmlGroups === null ? [] : self::nonEmpty($xmlGroups->include()->asArrayOfStrings());
        $exclude = $xmlGroups === null ? [] : array_diff(self::nonEmpty($xmlGroups->exclude()->asArrayOfStrings()), $include);

        // groups()/excludeGroups() throw when empty rather than returning [].
        $groups = $configuration->hasGroups() ? $configuration->groups() : [];
        $excludeGroups = $configuration->hasExcludeGroups() ? $configuration->excludeGroups() : [];

        return ! self::sameSet($groups, $include) || ! self::sameSet($excludeGroups, $exclude);
    }

    /**
     * @return list<string>
     */
    private static function defaultTestSuites(Configuration $configuration): array
    {
        if (! $configuration->hasDefaultTestSuite()) {
            return [];
        }

        return self::nonEmpty(explode(',', $configuration->defaultTestSuite()));
    }

    /**
     * @param  array<array-key, string>  $values
     * @return list<string>
     */
    private static function nonEmpty(array $values): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<array-key, string>  $actual
     * @param  array<array-key, string>  $expected
     */
    private static function sameSet(array $actual, array $expected): bool
    {
        $actual = array_values(array_unique($actual));
        $expected = array_values(array_unique($expected));

        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
