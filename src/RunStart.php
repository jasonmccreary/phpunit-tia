<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia;

use Throwable;

/**
 * Where the code stood when a run started: the branch, the commit, and the
 * content of every file the stored graph knows or the working tree changed.
 *
 * Tests execute against the code as it is while they run, not as it is when
 * the run ends. A full run therefore stamps its baseline from this snapshot:
 * a commit or an edit made while it ran (in an editor, another terminal, a
 * second agent) reached none of the tests that already passed, and the next
 * run must still see it as a change.
 */
final readonly class RunStart
{
    /**
     * @param  array<string, string>  $tree  project-relative path → content hash, see ChangedFiles::snapshotTree()
     */
    public function __construct(
        public string $branch,
        public ?string $sha,
        public array $tree,
    ) {}

    /**
     * Null when git can't answer, so the run falls back to reading the
     * baseline when it ends, exactly as it did before this snapshot existed.
     */
    public static function capture(string $projectRoot, string $storageMode): ?self
    {
        try {
            $changedFiles = new ChangedFiles($projectRoot);

            return new self(
                $changedFiles->currentBranch() ?? 'default',
                $changedFiles->currentSha(),
                $changedFiles->snapshotTree(array_values(array_unique([
                    ...self::trackedFiles($projectRoot, $storageMode),
                    ...$changedFiles->since(null) ?? [],
                ]))),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function trackedFiles(string $projectRoot, string $storageMode): array
    {
        $raw = (new FileState(Storage::resolve($projectRoot, $storageMode)))->read(Storage::GRAPH_KEY);
        $graph = $raw === null ? null : Graph::decode($raw, $projectRoot);

        if ($graph === null) {
            return [];
        }

        return array_values([...$graph->allTestFiles(), ...$graph->allSourceFiles()]);
    }
}
