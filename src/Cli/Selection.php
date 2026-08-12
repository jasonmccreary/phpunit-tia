<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Cli;

use JMac\Testing\PhpUnit\Tia\Tia;

/**
 * Turns the impact analysis into a Plan.
 *
 * Every uncertainty resolves to running more tests, never fewer: a wrapper bug
 * should cost time, not a missed regression.
 */
final readonly class Selection
{
    public function __construct(private Tia $tia) {}

    /**
     * @param  list<string>  $suiteFiles  Project-relative test files the configured suite would run.
     * @param  list<string>  $userPaths  Paths the user asked for, if any.
     */
    public function plan(array $suiteFiles, array $userPaths): Plan
    {
        if (! $this->tia->isActive()) {
            return Plan::runAll('no usable baseline graph');
        }

        if ($this->tia->hasUnlocatedTestsToRerun()) {
            return Plan::runAll('a test that must re-run has no resolvable file');
        }

        $candidates = array_unique(array_merge(
            $this->tia->affectedTestFiles(),
            $this->tia->testFilesToRerun(),
            $this->tia->testFilesWithoutCachedSuccess(),
            array_values(array_diff($suiteFiles, $this->tia->knownTestFiles())),
        ));

        // The suite is the authority on what may run at all: positional paths
        // bypass <exclude>, so emitting a file it would not run can execute
        // something the project deliberately keeps out, and fatal.
        $selected = array_values(array_intersect($candidates, $suiteFiles));

        if ($userPaths !== []) {
            $selected = array_values(array_filter(
                $selected,
                fn (string $file): bool => self::isUnder($file, $userPaths),
            ));
        }

        if ($selected === []) {
            return Plan::runNothing('nothing affected');
        }

        return Plan::runPaths($selected, sprintf(
            '%d of %d test files selected',
            count($selected),
            count($suiteFiles),
        ));
    }

    /**
     * A user-supplied argument narrows the selection rather than adding to it,
     * so `phpunit-tia tests/Feature` means "affected within tests/Feature".
     *
     * @param  list<string>  $paths
     */
    private static function isUnder(string $file, array $paths): bool
    {
        foreach ($paths as $path) {
            $path = rtrim($path, '/');

            if ($file === $path || str_starts_with($file, $path.'/')) {
                return true;
            }
        }

        return false;
    }
}
