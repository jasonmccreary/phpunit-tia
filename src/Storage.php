<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

/**
 * Resolves where the graph file lives on disk (§4.5): global cache keyed by
 * git remote/path so multiple clones/worktrees of the same repo share one
 * cache location, or a local .gitignored path.
 */
final class Storage
{
    // TODO: milestone 3
}
