<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Contracts;

/**
 * Minimal read/write seam over the graph's storage backend (§4.5).
 */
interface State
{
    public function read(string $key): ?string;

    public function write(string $key, string $value): void;

    public function delete(string $key): void;

    public function exists(string $key): bool;

    /** @return list<string> */
    public function keysWithPrefix(string $prefix): array;
}
