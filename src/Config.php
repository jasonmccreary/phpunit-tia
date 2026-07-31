<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;

/**
 * Loads the optional `phpunit-tia.php` config file (§7) — PHPUnit's own
 * ParameterCollection is flat string k/v, which can't express a list of
 * Resolver instances/class-strings, so anything beyond the "storage" knob
 * (a single flat parameter) lives in this file instead. Absent file or
 * absent/malformed `resolvers` key both mean "no resolvers", not an error —
 * this file is entirely optional and core works fine without it.
 */
final class Config
{
    /**
     * @return list<Resolver>
     */
    public static function loadResolvers(string $projectRoot): array
    {
        $file = rtrim($projectRoot, '/').'/phpunit-tia.php';

        if (! is_file($file)) {
            return [];
        }

        $config = require $file;

        if (! is_array($config) || ! is_array($config['resolvers'] ?? null)) {
            return [];
        }

        $resolvers = [];

        foreach ($config['resolvers'] as $resolver) {
            if ($resolver instanceof Resolver) {
                $resolvers[] = $resolver;

                continue;
            }

            if (is_string($resolver) && is_subclass_of($resolver, Resolver::class)) {
                $resolvers[] = new $resolver;
            }
        }

        return $resolvers;
    }
}
