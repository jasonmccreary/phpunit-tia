<?php

declare(strict_types=1);

// Throwaway fixture: stands in for a real migration file. Never executed by
// any test — its only role in fixture-app is to be a changed path that core
// can't map to a known edge, so only App\Support\MigrationResolver (wired
// via phpunit-tia.php) can connect it to tests/WidgetTest.php.
return static fn (): array => ['create' => 'widgets'];
