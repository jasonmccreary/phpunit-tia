<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests\Cli;

use JMac\Testing\PhpUnit\Tia\Cli\Arguments;
use JMac\Testing\PhpUnit\Tia\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ArgumentsTest extends TestCase
{
    #[Test]
    public function it_extracts_the_positional_paths(): void
    {
        $arguments = Arguments::fromArgv(['--filter', 'Foo', 'tests/AaTest.php', 'tests/BbTest.php', '--stop-on-failure']);

        $this->assertSame(['tests/AaTest.php', 'tests/BbTest.php'], $arguments->paths());
    }

    #[Test]
    public function it_hands_the_remaining_options_through_untouched(): void
    {
        $arguments = Arguments::fromArgv(['--filter', 'Foo', 'tests/AaTest.php', 'tests/BbTest.php', '--stop-on-failure']);

        $this->assertSame(['--filter', 'Foo', '--stop-on-failure'], $arguments->passthrough());
    }

    /**
     * An option value can be spelled exactly like a positional path. Only the
     * positional occurrence may be removed — stripping the value too would
     * silently change the filter the user asked for.
     */
    #[Test]
    public function it_keeps_an_option_value_spelled_like_a_positional_path(): void
    {
        $arguments = Arguments::fromArgv(['--filter', 'tests/AaTest.php', 'tests/AaTest.php']);

        $this->assertSame(['tests/AaTest.php'], $arguments->paths());
        $this->assertSame(['--filter', 'tests/AaTest.php'], $arguments->passthrough());
    }
}
