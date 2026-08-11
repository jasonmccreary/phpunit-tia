<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Cli;

use PHPUnit\TextUI\CliArguments\Builder as CliBuilder;

/**
 * Splits a phpunit argv into the positional test paths the user asked for and
 * the options to hand straight through to the real runner.
 *
 * PHPUnit's own CLI parser does the hard part: it knows which options take a
 * value, so `--filter tests/FooTest.php` is not mistaken for a path.
 */
final readonly class Arguments
{
    /**
     * @param  list<string>  $argv
     * @param  list<string>  $paths
     */
    private function __construct(private array $argv, private array $paths) {}

    /**
     * @param  list<string>  $argv  phpunit arguments, without the script name.
     */
    public static function fromArgv(array $argv): self
    {
        return new self($argv, (new CliBuilder)->fromParameters($argv)->arguments());
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * The original argv with the positional paths removed, ready to be
     * combined with a computed selection.
     *
     * The parser reports path values, not their positions, so removal is by
     * value — but only as many occurrences as it reported, scanning from the
     * end. That keeps an option value spelled like a path (`--filter
     * tests/FooTest.php`) intact, since positional paths conventionally come
     * last.
     *
     * @return list<string>
     */
    public function passthrough(): array
    {
        $removable = array_count_values($this->paths);
        $argv = $this->argv;

        for ($i = count($argv) - 1; $i >= 0; $i--) {
            $token = $argv[$i];

            if (($removable[$token] ?? 0) > 0) {
                $removable[$token]--;

                unset($argv[$i]);
            }
        }

        return array_values($argv);
    }
}
