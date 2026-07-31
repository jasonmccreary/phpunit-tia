<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use PHPUnit\TextUI\Configuration\Registry;
use Throwable;

/**
 * Resolves the set of project-relative paths that are considered test
 * files, driven by phpunit.xml's <testsuites> (via PHPUnit's own already-
 * parsed Configuration). Ported from Pest's Tia/TestPaths.php, dropping its
 * Pest-specific TestSuite::getInstance() fallback — a vanilla PHPUnit run
 * always has Registry::get() populated by the time this is ever called
 * (Graph::affected() only runs mid-suite or after, never during bootstrap).
 */
final readonly class TestPaths
{
    /**
     * @param  list<string>  $directories  Project-relative directory prefixes (no trailing slash).
     * @param  list<string>  $files  Project-relative file paths.
     * @param  list<string>  $suffixes  Filename suffixes (e.g. '.php').
     */
    public function __construct(
        private array $directories,
        private array $files,
        private array $suffixes,
    ) {}

    public static function fromProjectRoot(string $projectRoot): self
    {
        $directories = [];
        $files = [];
        $suffixes = [];

        try {
            $configuration = Registry::get();

            foreach ($configuration->testSuite() as $suite) {
                foreach ($suite->directories() as $directory) {
                    $rel = self::toRelative($directory->path(), $projectRoot);

                    if ($rel !== null) {
                        $directories[] = $rel;
                    }

                    $suffixes[] = $directory->suffix();
                }

                foreach ($suite->files() as $file) {
                    $rel = self::toRelative($file->path(), $projectRoot);

                    if ($rel !== null) {
                        $files[] = $rel;
                    }
                }
            }

            if ($suffixes === []) {
                foreach ($configuration->testSuffixes() as $suffix) {
                    $suffixes[] = $suffix;
                }
            }
        } catch (Throwable) {
            // Registry not initialized (e.g. a Graph built outside a real
            // PHPUnit run, such as in this package's own tests) — fall
            // through to defaults.
        }

        if ($suffixes === []) {
            $suffixes = ['.php'];
        }

        return new self(
            array_values(array_unique($directories)),
            array_values(array_unique($files)),
            array_values(array_unique($suffixes)),
        );
    }

    public function isTestFile(string $relativePath): bool
    {
        if (in_array($relativePath, $this->files, true)) {
            return true;
        }

        $matchesSuffix = false;

        foreach ($this->suffixes as $suffix) {
            if (str_ends_with($relativePath, $suffix)) {
                $matchesSuffix = true;

                break;
            }
        }

        if (! $matchesSuffix) {
            return false;
        }

        foreach ($this->directories as $dir) {
            if ($dir === '') {
                continue;
            }

            if (str_starts_with($relativePath, $dir.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function toRelative(string $value, string $projectRoot): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $real = @realpath($value);
        $resolved = $real === false ? $value : $real;

        $resolved = str_replace(DIRECTORY_SEPARATOR, '/', $resolved);
        $root = str_replace(DIRECTORY_SEPARATOR, '/', rtrim($projectRoot, '/\\')).'/';

        if (! str_starts_with($resolved.'/', $root)) {
            return null;
        }

        return rtrim(substr($resolved, strlen($root)), '/');
    }
}
