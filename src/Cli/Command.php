<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Cli;

use JMac\Testing\PhpUnit\Tia\Config;
use JMac\Testing\PhpUnit\Tia\Extension;
use JMac\Testing\PhpUnit\Tia\Tia;
use PHPUnit\TextUI\CliArguments\Builder as CliBuilder;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Computes the affected set before PHPUnit boots, then re-execs the real
 * runner with only the test files that need to run.
 *
 * The trait can only skip inside setUp(), so an unaffected TestCase is still
 * constructed. Selecting up front means it never is.
 */
final readonly class Command
{
    private const CONFIGURATION_CANDIDATES = ['phpunit.xml', 'phpunit.xml.dist'];

    /**
     * @param  list<string>  $argv  phpunit arguments, without the script name.
     */
    public function __construct(
        private string $projectRoot,
        private array $argv,
    ) {}

    public function run(): int
    {
        $arguments = Arguments::fromArgv($this->argv);
        $plan = $this->plan($arguments);

        $this->report($plan);

        if ($plan->isRunNothing()) {
            return 0;
        }

        return $this->execute($plan->isRunAll() ? $this->argv : [...$plan->paths(), ...$arguments->passthrough()]);
    }

    /**
     * Any uncertainty resolves to running everything. A wrapper bug should
     * cost time, never a missed regression.
     */
    private function plan(Arguments $arguments): Plan
    {
        if (! Tia::isEnabled()) {
            return Plan::runAll('disabled via PHPUNIT_TIA=0');
        }

        if (Tia::isFresh()) {
            return Plan::runAll('rebuilding the baseline via PHPUNIT_TIA_FRESH=1');
        }

        $configurationFile = $this->configurationFile();

        if ($configurationFile === null) {
            return Plan::runAll('no phpunit configuration found');
        }

        try {
            Registry::init(
                (new CliBuilder)->fromParameters($arguments->passthrough()),
                (new Loader)->load($configurationFile),
            );

            Tia::configure($this->projectRoot, $this->storageMode(), Config::loadResolvers($this->projectRoot));

            return (new Selection(Tia::instance()))->plan(
                SuiteFiles::fromProjectRoot($this->projectRoot)->all(),
                $arguments->paths(),
            );
        } catch (Throwable $e) {
            return Plan::runAll('could not plan the run: '.$e->getMessage());
        }
    }

    private function configurationFile(): ?string
    {
        foreach (self::CONFIGURATION_CANDIDATES as $candidate) {
            $path = $this->projectRoot.'/'.$candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Read from the same place the extension reads it, or the two resolve
     * different storage directories and the binary silently finds no graph.
     */
    private function storageMode(): string
    {
        foreach (Registry::get()->extensionBootstrappers() as $bootstrap) {
            if (($bootstrap['className'] ?? null) === Extension::class) {
                return $bootstrap['parameters']['storage'] ?? 'global';
            }
        }

        return 'global';
    }

    private function report(Plan $plan): void
    {
        fwrite(STDERR, 'phpunit-tia: '.$plan->reason().".\n");
    }

    /**
     * @param  list<string>  $arguments
     */
    private function execute(array $arguments): int
    {
        $binary = $this->phpunitBinary();

        if ($binary === null) {
            fwrite(STDERR, "phpunit-tia: could not find the phpunit binary.\n");

            return 1;
        }

        $process = new Process([PHP_BINARY, $binary, ...$arguments], $this->projectRoot, timeout: null);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        return $process->run(function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
    }

    private function phpunitBinary(): ?string
    {
        $configured = getenv('PHPUNIT_TIA_BINARY');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $default = $this->projectRoot.'/vendor/bin/phpunit';

        return is_file($default) ? $default : null;
    }
}
