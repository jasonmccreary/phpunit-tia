<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Throwaway git repository in the OS temp dir, for exercising ChangedFiles
 * and Fingerprint's git-shelling-out behavior without touching this
 * package's own repo.
 */
final class TempGitRepository
{
    private function __construct(private readonly string $path) {}

    public static function create(): self
    {
        $path = sys_get_temp_dir().'/phpunit-tia-git-'.bin2hex(random_bytes(8));
        mkdir($path, recursive: true);

        $repo = new self($path);
        $repo->run(['git', 'init', '-q', '-b', 'main']);
        $repo->run(['git', 'config', 'user.email', 'tia@example.test']);
        $repo->run(['git', 'config', 'user.name', 'Tia Test']);
        $repo->run(['git', 'config', 'commit.gpgsign', 'false']);

        return $repo;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function write(string $relativePath, string $contents): void
    {
        $absolute = $this->path.'/'.$relativePath;
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents($absolute, $contents);
    }

    /**
     * @param  list<string>  $paths
     */
    public function commit(string $message, array $paths = ['.']): string
    {
        $this->run(['git', 'add', ...$paths]);
        $this->run(['git', 'commit', '-q', '--allow-empty', '-m', $message]);

        return $this->sha();
    }

    public function sha(): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD'])->getOutput());
    }

    /**
     * @param  list<string>  $command
     */
    public function run(array $command): Process
    {
        $process = new Process($command, $this->path);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "Command failed: %s\n%s",
                implode(' ', $command),
                $process->getErrorOutput(),
            ));
        }

        return $process;
    }

    public function cleanup(): void
    {
        self::removeDirectory($this->path);
    }

    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.'/'.$item;

            if (is_dir($itemPath) && ! is_link($itemPath)) {
                self::removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
