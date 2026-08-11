<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Cli;

/**
 * The three things the wrapper can decide. See Plan for why "nothing" and
 * "all" are separate cases rather than one empty list.
 */
enum Outcome
{
    case All;
    case Nothing;
    case Paths;
}
