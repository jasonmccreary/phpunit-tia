<?php

declare(strict_types=1);

namespace App\Support;

use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;

/**
 * Throwaway example proving the Resolver extension point (phpunit-tia's
 * TIA.md §4.3, §8) is usable end-to-end, not just reachable via
 * Graph::setResolvers() in a unit test. A real framework package (e.g.
 * laravel/phpunit-tia-resolver) would parse the migration file to find the
 * table it creates and map that to every test touching that table; this
 * stand-in hardcodes the one mapping fixture-app needs to demonstrate it.
 *
 * database/migrations/ has no PHP source files of its own and no test ever
 * executes a migration directly, so core's direct-edge lookup and sibling-
 * directory fallback (Graph::applyUnknownSourceDirs()) both have nothing to
 * go on here — only a registered Resolver can connect a migration change to
 * the test that exercises the table it creates.
 */
final class MigrationResolver implements Resolver
{
    public function resolve(string $projectRoot, string $changedRelativePath): array
    {
        if ($changedRelativePath === 'database/migrations/2024_01_01_create_widgets_table.php') {
            return ['tests/WidgetTest.php'];
        }

        return [];
    }
}
