<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Tests;

use JMac\Testing\PhpUnit\Tia\Fingerprint;
use JMac\Testing\PhpUnit\Tia\Tests\Support\TempGitRepository;
use PHPUnit\Framework\Attributes\Test;

final class FingerprintTest extends TestCase
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
    public function it_includes_the_current_php_version(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->path());

        $this->assertSame(
            PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            $fingerprint['environmental']['php_version'],
        );
    }

    #[Test]
    public function it_hashes_tracked_structural_files(): void
    {
        $this->repo->write('composer.lock', '{"content-hash":"abc"}');
        $this->repo->write('phpunit.xml', '<phpunit></phpunit>');
        $this->repo->commit('add config files');

        $fingerprint = Fingerprint::compute($this->repo->path());

        $this->assertNotNull($fingerprint['structural']['composer_lock']);
        $this->assertNotNull($fingerprint['structural']['phpunit_xml']);
        $this->assertNull($fingerprint['structural']['phpunit_xml_dist']);
    }

    #[Test]
    public function it_treats_gitignored_files_as_absent(): void
    {
        $this->repo->write('.gitignore', "composer.lock\n");
        $this->repo->commit('add gitignore');

        $this->repo->write('composer.lock', '{"content-hash":"abc"}');

        $fingerprint = Fingerprint::compute($this->repo->path());

        $this->assertNull($fingerprint['structural']['composer_lock']);
    }

    #[Test]
    public function it_hashes_files_outside_a_git_repo(): void
    {
        $plainDir = sys_get_temp_dir().'/phpunit-tia-no-git-'.bin2hex(random_bytes(8));
        mkdir($plainDir);
        file_put_contents($plainDir.'/composer.lock', '{"content-hash":"abc"}');

        try {
            $fingerprint = Fingerprint::compute($plainDir);

            $this->assertNotNull($fingerprint['structural']['composer_lock']);
        } finally {
            unlink($plainDir.'/composer.lock');
            rmdir($plainDir);
        }
    }

    #[Test]
    public function structural_matches_is_true_for_identical_fingerprints(): void
    {
        $a = ['structural' => ['schema' => 1, 'composer_lock' => 'hash-a'], 'environmental' => []];
        $b = ['structural' => ['schema' => 1, 'composer_lock' => 'hash-a'], 'environmental' => []];

        $this->assertTrue(Fingerprint::structuralMatches($a, $b));
    }

    #[Test]
    public function structural_matches_is_false_when_the_schema_changes(): void
    {
        // Unlike structuralDrift(), structuralMatches() does not skip
        // schema — a schema bump alone must invalidate the graph (§4.4:
        // insurance against reading a graph from a previous dev iteration).
        $a = ['structural' => ['schema' => 1, 'composer_lock' => 'hash-a'], 'environmental' => []];
        $b = ['structural' => ['schema' => 2, 'composer_lock' => 'hash-a'], 'environmental' => []];

        $this->assertFalse(Fingerprint::structuralMatches($a, $b));
    }

    #[Test]
    public function structural_matches_is_false_when_composer_lock_changes(): void
    {
        $a = ['structural' => ['schema' => 1, 'composer_lock' => 'hash-a'], 'environmental' => []];
        $b = ['structural' => ['schema' => 1, 'composer_lock' => 'hash-b'], 'environmental' => []];

        $this->assertFalse(Fingerprint::structuralMatches($a, $b));
    }

    #[Test]
    public function structural_drift_lists_changed_keys_but_not_schema(): void
    {
        $stored = ['structural' => ['schema' => 1, 'composer_lock' => 'hash-a', 'phpunit_xml' => 'x'], 'environmental' => []];
        $current = ['structural' => ['schema' => 2, 'composer_lock' => 'hash-b', 'phpunit_xml' => 'x'], 'environmental' => []];

        $this->assertSame(['composer_lock'], Fingerprint::structuralDrift($stored, $current));
    }

    #[Test]
    public function environmental_drift_lists_changed_keys(): void
    {
        $stored = ['structural' => [], 'environmental' => ['php_version' => '8.3']];
        $current = ['structural' => [], 'environmental' => ['php_version' => '8.4']];

        $this->assertSame(['php_version'], Fingerprint::environmentalDrift($stored, $current));
    }
}
