<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use Symfony\Component\Process\Process;

/**
 * Cache-invalidation fingerprint (§4.4). `structural` fields force a full
 * graph rebuild when they change (composer.lock drifting means autoloaded
 * classes may have moved); `environmental` fields only drop cached pass/fail
 * results (edges are probably still valid, but a previously-passing test
 * might behave differently under a different PHP version). Ported from
 * Pest's Fingerprint.php, dropping the Vite/JS-specific fields (out of scope
 * for core — see §1 non-goals) and replacing its Symfony\Finder-based
 * isTrackedByGit() with a `git check-ignore` shell-out, so this package
 * doesn't need symfony/finder as a dependency just for that one check.
 */
final class Fingerprint
{
    private const int SCHEMA_VERSION = 1;

    /**
     * @return array{structural: array<string, int|string|null>, environmental: array<string, int|string|null>}
     */
    public static function compute(string $projectRoot): array
    {
        return [
            'structural' => [
                'schema' => self::SCHEMA_VERSION,
                'composer_lock' => self::trackedHash($projectRoot, 'composer.lock'),
                'phpunit_xml' => self::trackedHash($projectRoot, 'phpunit.xml'),
                'phpunit_xml_dist' => self::trackedHash($projectRoot, 'phpunit.xml.dist'),
            ],
            'environmental' => [
                // Design doc §1/§4.4 calls for minor-version granularity —
                // Pest's own equivalent field is named php_minor but only
                // ever stores PHP_MAJOR_VERSION; that looks like a latent
                // bug there rather than something worth reproducing here.
                'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function structuralMatches(array $a, array $b): bool
    {
        $aStructural = self::structuralOnly($a);
        $bStructural = self::structuralOnly($b);

        ksort($aStructural);
        ksort($bStructural);

        return $aStructural === $bStructural;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    public static function structuralDrift(array $stored, array $current): array
    {
        return self::detectDrift(
            self::structuralOnly($stored),
            self::structuralOnly($current),
            'schema',
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    public static function environmentalDrift(array $stored, array $current): array
    {
        return self::detectDrift(
            self::environmentalOnly($stored),
            self::environmentalOnly($current),
        );
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return list<string>
     */
    private static function detectDrift(array $a, array $b, ?string $skipKey = null): array
    {
        $drifts = [];

        foreach ($a as $key => $value) {
            if ($key === $skipKey) {
                continue;
            }

            if (($b[$key] ?? null) !== $value) {
                $drifts[] = $key;
            }
        }

        foreach ($b as $key => $value) {
            if ($key === $skipKey) {
                continue;
            }

            if (! array_key_exists($key, $a) && $value !== null) {
                $drifts[] = $key;
            }
        }

        return array_values(array_unique($drifts));
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private static function structuralOnly(array $fingerprint): array
    {
        return self::bucket($fingerprint, 'structural');
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private static function environmentalOnly(array $fingerprint): array
    {
        return self::bucket($fingerprint, 'environmental');
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     * @return array<string, mixed>
     */
    private static function bucket(array $fingerprint, string $key): array
    {
        $raw = $fingerprint[$key] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $normalised = [];

        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $normalised[$k] = $v;
            }
        }

        return $normalised;
    }

    private static function trackedHash(string $projectRoot, string $relativePath): ?string
    {
        if (! self::isTrackedByGit($projectRoot, $relativePath)) {
            return null;
        }

        return self::hashIfExists($projectRoot.'/'.$relativePath);
    }

    /**
     * True when the file exists and isn't gitignored.
     *
     * Gitignored lockfiles (e.g. composer.lock excluded from the repo)
     * regenerate per-machine, which would otherwise force a fingerprint
     * mismatch on every run.
     */
    private static function isTrackedByGit(string $projectRoot, string $relativePath): bool
    {
        if (! is_file($projectRoot.'/'.$relativePath)) {
            return false;
        }

        if (! is_dir($projectRoot.'/.git') && ! is_file($projectRoot.'/.git')) {
            return true;
        }

        $process = new Process(
            ['git', 'check-ignore', '--no-index', '-q', $relativePath],
            $projectRoot,
        );
        $process->setTimeout(5.0);
        $process->run();

        // check-ignore exits 0 when the path IS ignored, 1 when it is not.
        return $process->getExitCode() !== 0;
    }

    private static function hashIfExists(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $hash = @hash_file('xxh128', $path);

        return $hash === false ? null : $hash;
    }
}
