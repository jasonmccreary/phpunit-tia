<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

/**
 * xxh128 content hashing (§4.2), used instead of mtime so CI checkouts with
 * fresh mtimes don't false-positive every file as changed. PHP files are
 * hashed on their token stream with whitespace/comments stripped first, so
 * a purely cosmetic edit (reformatting, adding a comment) doesn't count as
 * a change. Ported from Pest's ContentHash.php, dropping the Blade/JS
 * branches (framework-specific, out of scope for core — see §1 non-goals).
 */
final class ContentHash
{
    public static function of(string $absolute): string|false
    {
        $raw = @file_get_contents($absolute);

        if ($raw === false) {
            return false;
        }

        return self::ofContent($absolute, $raw);
    }

    public static function ofContent(string $path, string $raw): string
    {
        if (str_ends_with(strtolower($path), '.php')) {
            return self::hashPhpContent($raw);
        }

        return hash('xxh128', $raw);
    }

    private static function hashPhpContent(string $raw): string
    {
        $tokens = @token_get_all($raw);

        if ($tokens === []) {
            return hash('xxh128', $raw);
        }

        $normalised = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $normalised .= $token[1];
            } else {
                $normalised .= $token;
            }
        }

        return hash('xxh128', $normalised);
    }
}
