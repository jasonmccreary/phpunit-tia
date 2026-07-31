# Design decisions & verified findings

Extracted from `TIA.md` (the original v1 design/build doc, now a completed
milestone log — see `docs/roadmap.md` for what's still open). This file
holds the "why" reasoning that isn't fully captured by code comments or the
README: an architectural constraint proved against PHPUnit source, and two
`@internal`-marked PHPUnit bugs found empirically during milestone 1. Both
still justify real code in `src/Extension.php` and `src/Traits/RunWithTia.php`.

---

## Why the trait can only skip, never fake a pass

This constrains the whole design, so it's worth stating precisely, with the
exact PHPUnit source that proves it (`vendor/phpunit/phpunit/src/Framework/TestCase.php`,
verified against this package's own pinned 13.2.6 install):

- `run()` and `runBare()` are both `final`.
- `runBare()` calls the private method `runTest()` (`$this->testResult = $this->runTest();`).
- `runTest()` is `private` and unconditionally does `$this->{$this->methodName}(...$testArguments)` — it invokes the real, hand-written test method by name. Private methods aren't virtual in PHP, so no trait or subclass can intercept this call.
- The only genuinely overridable hook between "setUp succeeds" and "the real method body runs" is `setUp()` itself (`protected`, not final).

So `RunWithTia::setUp()` has exactly one lever: decide before returning
whether to let PHPUnit continue into the real test body, or throw. Throwing
`PHPUnit\Framework\SkippedTestError` (or `PHPUnit\Framework\SkippedTest`,
whichever's public in the target version) is the only way to stop
`runTest()` from being reached, and PHPUnit will report that as **Skipped**,
not **Passed**.

`addToAssertionCount()` (`final public`) is callable from `setUp()` before
throwing, so the skipped test can still carry the correct cached assertion
count forward — but the status line is what it is.

**Design consequence**: expose a config flag so teams running with
`--fail-on-skipped` (or treating any skip as CI-red) can opt out per-suite,
and make the skip message self-documenting: `"TIA: unaffected since <sha>,
last run passed"`. This mirrors what Pest's own `Graph::shouldRerunStatus()`
already has to account for — it checks `$configuration->failOnSkipped()` /
`failOnRisky()` / etc. before deciding a cached non-success status is even
safe to replay. `Graph::shouldRerunStatus()` in this package ports that same
policy check, just applied to the *skip* decision instead of a replay
decision — see `Tia::shouldRerunStatus()`'s doc comment for the one
deviation from the literal pseudocode this required (checking the
about-to-happen skip, not the cached status, which is always a plain
success).

Related, and why a cached **failure** is never replayed either (only a
cached pass): replaying a stale failure message via the same `setUp()`
trick is mechanically possible but actively unhelpful — a fresh failure
needs a fresh stack trace/diff, not a stale cached one. Risky/error/
incomplete tests are left to actually re-run for the same reason. This is a
deliberate simplification vs. Pest's four-way `ReplayType` enum
(pass/skip/incomplete/failure-replay), not an oversight — revisit only if a
concrete case for replaying a cached failure shows up (see `docs/roadmap.md`).

---

## Milestone 1 findings: two `@internal` PHPUnit bugs (verified against 13.2.6)

Verified empirically against real PHPUnit 12.5.33 and 13.2.6 installs
(source-read against both, behavior identical in both). Both are
`@internal`-marked PHPUnit internals, not documented behavior, so treat this
as "verified snapshot of 13.2.6," not a permanent guarantee — re-verify
against this package's own pinned install if either code path changes on a
future PHPUnit upgrade.

### Bug 1 — filter-registry crash

`PHPUnit\Runner\CodeCoverage::init()` (`vendor/phpunit/phpunit/src/Runner/CodeCoverage.php:78`)
calls `$codeCoverageFilterRegistry->init($configuration)` with no second
argument, so `CodeCoverageFilterRegistry::init()`'s `$force` parameter
defaults to `false` (`vendor/phpunit/phpunit/src/TextUI/Configuration/CodeCoverageFilterRegistry.php:52`).
That method only builds `$this->filter` when `$configuration->hasCoverageReport()`
is true (i.e. a `<coverage><report>` target like `clover`/`html`/`php` is
configured) — it does **not** check whether an extension called
`requireCodeCoverageCollection()`. But `CodeCoverage::init()`'s own
early-return check three lines later *does* account for the extension flag
(`!$configuration->hasCoverageReport() && !$extensionRequiresCodeCoverageCollection`),
so with an extension requiring coverage and zero report targets configured,
execution proceeds into `$codeCoverageFilterRegistry->get()`
(`CodeCoverageFilterRegistry.php:44`), which does `assert($this->filter !== null)`
— and crashes, because the filter was never built. Reproduced with
`zend.assertions=1` (PHP's dev default); with assertions compiled out it
would instead be a `TypeError` on `get()`'s non-nullable `Filter` return
type. Confirmed byte-identical in both `CodeCoverage.php` and
`CodeCoverageFilterRegistry.php` between PHPUnit 12.5.33 and 13.2.6.

**Fix**: `Extension::bootstrap()` pre-populates the registry itself —
`CodeCoverageFilterRegistry::instance()->init($configuration, true)` —
before calling `$facade->requireCodeCoverageCollection()`. This relies on an
`@internal` class, a deliberate tradeoff (chosen over requiring every
consumer's `phpunit.xml` to add a throwaway `<coverage><report>` block) so
the package keeps its promise of "register the extension, add the trait,
nothing else." Risk is contained: this package pins to one exact PHPUnit
minor line, and the package's own test suite (running against that pinned
version) will catch a signature/behavior change immediately rather than a
consumer hitting it silently in production.

### Bug 2 — silent full-suite skip with no driver

Independent of bug 1. `CodeCoverage::init()` returns a
`CodeCoverageInitializationStatus` enum (`NOT_REQUESTED` / `SUCCEEDED` /
`FAILED`) — `FAILED` when `activate()`'s internal `Selector` can't find
pcov/xdebug/phpdbg and throws `NoCodeCoverageDriverAvailableException`
(caught internally, converted to a `testRunnerTriggeredPhpunitWarning`
event, not rethrown). `TextUI\Application::run()` (`Application.php:225-234`)
only calls `$runner->run(...)` when that status is `NOT_REQUESTED` or
`SUCCEEDED` — on `FAILED` it skips test execution **entirely**, with no
exception and no clear error: the process exits 1 with just `"No tests
executed!"` printed. So an extension that unconditionally calls
`requireCodeCoverageCollection()` on a machine with no coverage driver
doesn't disable itself gracefully — it silently prevents the *entire test
suite* from running.

**Fix**: `Extension::bootstrap()` checks `function_exists('pcov\\start') &&
ini_get('pcov.enabled')` / `xdebug_info('mode')` containing `'coverage'`
(mirrors Pest's driver-detection logic, not a blunt `extension_loaded()`
check — pcov can be loaded but ini-disabled, xdebug can be loaded in a mode
without coverage) **before** calling `requireCodeCoverageCollection()`. No
driver → write one line to STDERR and return from `bootstrap()` without
requiring coverage; PHPUnit then sees `NOT_REQUESTED` and runs the suite
normally, just without TIA active for that run. Verified end-to-end in
`fixture-app/`: with no driver, tests execute and pass; forcing past this
check to isolate bug 1 reproduces the clean warning-event path with no
crash, confirming the two fixes are independent and both necessary.
