<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Contracts;

/**
 * Extension point for framework-specific impact heuristics (§4.3, §8) —
 * e.g. a laravel/phpunit-tia-resolver package mapping migrations to tables
 * to tests. Deliberately kept out of core.
 */
interface Resolver
{
    /**
     * Called with every changed path core couldn't map to a known source edge.
     *
     * @return list<string> affected test files, or [] if this resolver has no opinion
     */
    public function resolve(string $projectRoot, string $changedRelativePath): array;
}
