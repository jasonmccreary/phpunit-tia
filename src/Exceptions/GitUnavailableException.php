<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Exceptions;

use RuntimeException;

final class GitUnavailableException extends RuntimeException
{
    public function __construct(string $feature)
    {
        parent::__construct(sprintf('phpunit-tia: "%s" requires git.', $feature));
    }
}
