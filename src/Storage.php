<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

/**
 * Resolves where the graph file lives on disk (§4.5, §7).
 *
 * `global` (the default): `~/.phpunit-tia/<project-slug>-<hash-of-git-origin-or-path>/`
 * — outside the repo, keyed by git remote so CI runners with ephemeral
 * checkouts and multiple clones/worktrees of the same repo share one cache
 * location without a .gitignore entry or path collisions.
 *
 * `local`: `<project>/.phpunit-tia/` — inside the repo, already covered by
 * this package's own .gitignore pattern for consumers who'd rather keep the
 * cache alongside the checkout (e.g. a single long-lived CI workspace).
 */
final class Storage
{
    /** Shared FileState key for the encoded Graph JSON (WriteGraph writes it, Tia reads it). */
    public const string GRAPH_KEY = 'graph.json';

    public static function resolve(string $projectRoot, string $mode = 'global'): string
    {
        if ($mode === 'local') {
            return rtrim($projectRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.phpunit-tia';
        }

        $home = self::homeDir();

        if ($home === null) {
            return rtrim($projectRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.phpunit-tia';
        }

        return $home.DIRECTORY_SEPARATOR.'.phpunit-tia'.DIRECTORY_SEPARATOR.self::projectKey($projectRoot);
    }

    public static function purge(string $projectRoot, string $mode = 'global'): void
    {
        $dir = self::resolve($projectRoot, $mode);

        if (! is_dir($dir)) {
            return;
        }

        self::removeRecursive($dir);
    }

    private static function removeRecursive(string $dir): void
    {
        $entries = @scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($path) && ! is_link($path)) {
                self::removeRecursive($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }

    private static function homeDir(): ?string
    {
        foreach (['HOME', 'USERPROFILE'] as $key) {
            $value = getenv($key);

            if (is_string($value) && $value !== '' && is_dir($value)) {
                return rtrim($value, '/\\');
            }
        }

        return null;
    }

    private static function projectKey(string $projectRoot): string
    {
        $origin = self::originIdentity($projectRoot);

        $realpath = @realpath($projectRoot);
        $input = $origin ?? ($realpath === false ? $projectRoot : $realpath);

        $hash = substr(hash('sha256', $input), 0, 16);
        $slug = self::slug(basename($projectRoot));

        return $slug === '' ? $hash : $slug.'-'.$hash;
    }

    private static function originIdentity(string $projectRoot): ?string
    {
        $url = self::rawOriginUrl($projectRoot);

        if ($url === null) {
            return null;
        }

        // git@host:org/repo(.git)
        if (preg_match('#^[\w.-]+@([\w.-]+):([\w./-]+?)(?:\.git)?/?$#', $url, $m) === 1) {
            return strtolower($m[1].'/'.$m[2]);
        }

        // scheme://[user@]host[:port]/org/repo(.git) — https, ssh, git, file
        if (preg_match('#^[a-z]+://(?:[^@/]+@)?([^/:]+)(?::\d+)?/([\w./-]+?)(?:\.git)?/?$#i', $url, $m) === 1) {
            return strtolower($m[1].'/'.$m[2]);
        }

        return strtolower($url);
    }

    private static function rawOriginUrl(string $projectRoot): ?string
    {
        $config = $projectRoot.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'config';

        if (! is_file($config)) {
            return null;
        }

        $raw = @file_get_contents($config);

        if ($raw === false) {
            return null;
        }

        if (preg_match('/\[remote "origin"\][^\[]*?url\s*=\s*(\S+)/s', $raw, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    private static function slug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
