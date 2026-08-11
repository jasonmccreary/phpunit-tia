<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Cli;

use PHPUnit\TextUI\Configuration\Registry;
use SebastianBergmann\FileIterator\Facade as FileIteratorFacade;

/**
 * The set of test files the configured suite would actually run, as
 * project-relative paths.
 *
 * This is the authority the selection is intersected with. Positional paths
 * bypass <testsuite><exclude> — handing PHPUnit an excluded file runs it, and
 * can fatal — so a candidate that is not in this list must never be emitted.
 *
 * It reads the same source PHPUnit's own TestSuiteMapper does, and applies the
 * exclusions with the same call, so the two cannot drift apart.
 */
final readonly class SuiteFiles
{
    /**
     * @param  list<string>  $files  Project-relative test file paths.
     */
    private function __construct(private array $files) {}

    public static function fromProjectRoot(string $projectRoot): self
    {
        $configuration = Registry::get();
        $include = $configuration->includeTestSuites();
        $excludedSuites = $configuration->excludeTestSuites();

        $files = [];

        foreach ($configuration->testSuite() as $suite) {
            // --testsuite / --exclude-testsuite restrict the run to named
            // suites. Ignoring them would select files the user excluded, and
            // pull in every never-recorded file from those suites.
            if ($include !== [] && ! in_array($suite->name(), $include, true)) {
                continue;
            }

            if (in_array($suite->name(), $excludedSuites, true)) {
                continue;
            }

            $exclude = [];

            foreach ($suite->exclude() as $excluded) {
                $exclude[] = $excluded->path();
            }

            foreach ($suite->directories() as $directory) {
                $found = (new FileIteratorFacade)->getFilesAsArray(
                    $directory->path(),
                    $directory->suffix(),
                    $directory->prefix(),
                    $exclude,
                );

                foreach ($found as $file) {
                    $files[] = $file;
                }
            }

            foreach ($suite->files() as $file) {
                $files[] = $file->path();
            }
        }

        return new self(self::toRelative($files, $projectRoot));
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->files;
    }

    public function contains(string $relativePath): bool
    {
        return in_array($relativePath, $this->files, true);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function toRelative(array $paths, string $projectRoot): array
    {
        $root = str_replace(DIRECTORY_SEPARATOR, '/', rtrim($projectRoot, '/\\')).'/';
        $relative = [];

        foreach ($paths as $path) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

            if (str_starts_with($path, $root)) {
                $relative[] = substr($path, strlen($root));
            }
        }

        return array_values(array_unique($relative));
    }
}
