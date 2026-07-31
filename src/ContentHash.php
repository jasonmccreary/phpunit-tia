<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

/**
 * xxh128 content hashing (§4.2), used instead of mtime so CI checkouts with
 * fresh mtimes don't false-positive every file as changed.
 */
final class ContentHash
{
    // TODO: milestone 2
}
