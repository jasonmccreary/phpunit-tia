<?php

declare(strict_types=1);

namespace JMac\Testing\PhpUnit\Tia\Subscribers;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Metadata\Metadata;
use PHPUnit\Metadata\MetadataCollection;
use PHPUnit\TextUI\Configuration\Registry;

/**
 * `#[Covers*]` restricts the coverage PHPUnit collects for a test down to
 * the declared target, so the edges WriteGraph records from that coverage
 * miss every other file the test actually touched (issue #13). PHPUnit's
 * own `--disable-coverage-targeting` lifts the restriction without
 * disturbing the attribute; short of that flag there is no way to record a
 * complete graph, so this only warns once per run rather than refusing to
 * record — refusing would penalize suites that already know about the
 * option, or use `#[Covers*]` on only a handful of tests.
 */
final class WarnCoversTargeting implements PreparedSubscriber
{
    private bool $warned = false;

    public function notify(Prepared $event): void
    {
        if ($this->warned || Registry::get()->disableCoverageTargeting()) {
            return;
        }

        $test = $event->test();

        if (! $test instanceof TestMethod || ! $this->usesCoversMetadata($test->metadata())) {
            return;
        }

        $this->warned = true;

        fwrite(STDERR, "phpunit-tia: {$test->nameWithClass()} uses #[Covers*] metadata, which restricts coverage below what TIA needs — recorded edges will be incomplete. Record baselines with --disable-coverage-targeting (see README).\n");
    }

    private function usesCoversMetadata(MetadataCollection $metadata): bool
    {
        foreach ($metadata as $item) {
            if ($this->isCoversMetadata($item)) {
                return true;
            }
        }

        return false;
    }

    private function isCoversMetadata(Metadata $metadata): bool
    {
        return $metadata->isCoversNamespace()
            || $metadata->isCoversClass()
            || $metadata->isCoversClassesThatExtendClass()
            || $metadata->isCoversClassesThatImplementInterface()
            || $metadata->isCoversTrait()
            || $metadata->isCoversFunction()
            || $metadata->isCoversMethod();
    }
}
