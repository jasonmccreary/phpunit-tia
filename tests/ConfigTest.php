<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Config;
use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exercises the optional phpunit-tia.php config file (§7) that
 * Extension::bootstrap() loads to register Resolvers — PHPUnit's own
 * ParameterCollection is flat string k/v and can't express a list of
 * instances/class-strings, so this is the escape hatch the doc anticipated.
 */
final class ConfigTest extends TestCase
{
    private TempGitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = TempGitRepository::create();
    }

    protected function tearDown(): void
    {
        if ($this->skippedByTia()) {
            return;
        }

        $this->repo->cleanup();
    }

    #[Test]
    public function it_returns_no_resolvers_when_the_config_file_is_absent(): void
    {
        $this->assertSame([], Config::loadResolvers($this->repo->path()));
    }

    #[Test]
    public function it_returns_no_resolvers_when_the_config_file_has_no_resolvers_key(): void
    {
        $this->repo->write('phpunit-tia.php', "<?php\n\nreturn [];\n");

        $this->assertSame([], Config::loadResolvers($this->repo->path()));
    }

    #[Test]
    public function it_instantiates_resolvers_registered_by_class_string(): void
    {
        $this->repo->write('phpunit-tia.php', <<<'PHP'
            <?php

            return [
                'resolvers' => [
                    JMac\Testing\PhpUnit\Tia\Tests\ConfigTestExampleResolver::class,
                ],
            ];
            PHP);

        $resolvers = Config::loadResolvers($this->repo->path());

        $this->assertCount(1, $resolvers);
        $this->assertInstanceOf(ConfigTestExampleResolver::class, $resolvers[0]);
    }

    #[Test]
    public function it_accepts_already_instantiated_resolvers(): void
    {
        $this->repo->write('phpunit-tia.php', <<<'PHP'
            <?php

            return [
                'resolvers' => [
                    new JMac\Testing\PhpUnit\Tia\Tests\ConfigTestExampleResolver,
                ],
            ];
            PHP);

        $resolvers = Config::loadResolvers($this->repo->path());

        $this->assertCount(1, $resolvers);
        $this->assertInstanceOf(ConfigTestExampleResolver::class, $resolvers[0]);
    }

    #[Test]
    public function it_ignores_entries_that_are_not_resolvers(): void
    {
        $this->repo->write('phpunit-tia.php', <<<'PHP'
            <?php

            return [
                'resolvers' => [
                    stdClass::class,
                    123,
                ],
            ];
            PHP);

        $this->assertSame([], Config::loadResolvers($this->repo->path()));
    }
}

final class ConfigTestExampleResolver implements Resolver
{
    public function resolve(string $projectRoot, string $changedRelativePath): array
    {
        return [];
    }
}
