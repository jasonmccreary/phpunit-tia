<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Cli;

/**
 * What the wrapper decided to do, and why.
 *
 * The two empty outcomes are deliberately distinct types rather than an empty
 * list: "nothing to run" exits clean, while "cannot narrow safely" runs the
 * whole suite. Handing PHPUnit an empty path list would run everything, so
 * collapsing them would turn a no-op into a full run — or worse, a full run
 * into a silent no-op.
 */
final readonly class Plan
{
    /**
     * @param  list<string>  $paths
     */
    private function __construct(
        private Outcome $outcome,
        private array $paths,
        private string $reason,
    ) {}

    public static function runAll(string $reason): self
    {
        return new self(Outcome::All, [], $reason);
    }

    public static function runNothing(string $reason): self
    {
        return new self(Outcome::Nothing, [], $reason);
    }

    /**
     * @param  list<string>  $paths
     */
    public static function runPaths(array $paths, string $reason): self
    {
        return new self(Outcome::Paths, $paths, $reason);
    }

    public function isRunAll(): bool
    {
        return $this->outcome === Outcome::All;
    }

    public function isRunNothing(): bool
    {
        return $this->outcome === Outcome::Nothing;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
