# PHPUnit TIA — Design Doc (v1)

Test Impact Analysis for vanilla PHPUnit, shipped as a composer package: a
`PHPUnit\Runner\Extension\Extension` (records a test↔source dependency graph)
plus a `RunWithTia` trait projects drop into their base `TestCase` (skips
re-running tests the graph says are unaffected and last known to pass).

This doc was written against Pest's actual TIA source as a reference
implementation, and against PHPUnit 12.x's real extension surface (verified
by reading vendor source, not docs). Every claim about what PHPUnit's public
API can/can't do was checked against code, not assumed — see §6,
[Verified PHPUnit surface](#6-verified-phpunit-surface).

All paths below are absolute so this doc still resolves correctly once moved
out of the Pest repo into the new plugin project: `/Users/jason/workspace/pest/src/...`
paths point at Pest's actual TIA source (reference only — reimplement, don't
copy verbatim); `.../vendor/phpunit/phpunit/src/...` paths point at a
PHPUnit 12 checkout used to verify the extension API (any project with
`phpunit/phpunit` installed works — keep this repo, or some other
PHPUnit-installed project, around to re-check them if needed).

---

## 1. Goals (v1)

- `composer require --dev vendor/phpunit-tia`, register in `phpunit.xml`, add one trait to the base `TestCase`. No CLI wrapper, no forked `phpunit` binary.
- Record which source files each test method actually executes (via PHPUnit's own coverage session — no manual pcov/xdebug driving needed, see §4.1).
- On the next run, diff against git, compute the affected test set, and mark every *unaffected, last-known-passing* test as **skipped** with a message identifying it as a TIA cache hit — never claim a fake "passed" (that's architecturally unavailable in vanilla PHPUnit, see §3).
- Persist the graph locally (gitignored), keyed per-branch, invalidated on structural drift (composer.lock, phpunit.xml, PHP minor version).

### Non-goals (v1)

- No `--filter`-based "only execute affected tests" wrapper binary. That's a legitimate v2 (see §8) but is a separate mechanism (re-exec with computed `--filter`) from the extension+trait combo and shouldn't block v1.
- No framework-specific heuristics (Laravel migrations→tables, Inertia pages, Blade includes) in core. Ship a `Resolver` extension point (§6.4) so a `laravel/*` companion package can add them later — don't bake Laravel into the core package.
- No parallel-runner support (ParaTest) in v1. Pest's `Tia.php` has substantial worker/partial-merge machinery (`handleWorker`, `flushWorkerPartial`, `mergeWorkerReplayPartials`) purely because of this — skip it until core works single-process.
- No remote/CI baseline sync (Pest's `BaselineSync.php`) — v1 assumes the graph lives in a local persistent cache (or is warmed once in CI and cached by the CI provider's own cache action, which needs zero code from us).

---

## 2. Two packages, one repo

```
phpunit-tia/
  src/
    Extension.php            # implements PHPUnit\Runner\Extension\Extension
    Recorder.php              # subscribes to Test events, reads PHPUnit's own coverage data
    ChangedFiles.php           # git diff/status + content hashing
    ContentHash.php
    Graph.php                  # the dependency graph + affected() algorithm
    Fingerprint.php            # structural/environmental cache-invalidation fingerprint
    Storage.php                 # where the graph file lives on disk
    Contracts/
      State.php
      Resolver.php              # extension point for framework-specific heuristics
    FileState.php
  trait/
    RunWithTia.php              # the setUp()-hook trait for the test base class
  tests/
```

Ship as one composer package to start (`vendor/phpunit-tia`), split later if
the trait needs to be usable without the extension (unlikely — the trait is
useless without a graph the extension produced).

---

## 3. Why the trait can only skip, never fake a pass

This constrains the whole design, so it's worth stating precisely, with the
exact PHPUnit source that proves it (`/Users/jason/workspace/blueprint/vendor/phpunit/phpunit/src/Framework/TestCase.php`):

- `run()` (line 354) and `runBare()` (line 472) are both `final`.
- `runBare()` calls the private method `runTest()` (line 515: `$this->testResult = $this->runTest();`).
- `runTest()` (line 1652) is `private` and unconditionally does `$this->{$this->methodName}(...$testArguments)` (line 1657) — it invokes the real, hand-written test method by name. Private methods aren't virtual in PHP, so no trait or subclass can intercept this call.
- The only genuinely overridable hook between "setUp succeeds" and "the real method body runs" is `setUp()` itself (line 272, `protected`, not final).

So `RunWithTia::setUp()` has exactly one lever: decide before returning
whether to let PHPUnit continue into the real test body, or throw. Throwing
`PHPUnit\Framework\SkippedTestError` (or `PHPUnit\Framework\SkippedTest`,
whichever's public in the target version) is the only way to stop
`runTest()` from being reached, and PHPUnit will report that as **Skipped**,
not **Passed**.

`addToAssertionCount()` (line 815, `final public`) is callable from `setUp()`
before throwing, so the skipped test can still carry the correct cached
assertion count forward — but the status line is what it is.

**Design consequence**: expose a config flag so teams running with
`--fail-on-skipped` (or treating any skip as CI-red) can opt out per-suite,
and make the skip message self-documenting: `"TIA: unaffected since <sha>, last run passed"`.
This mirrors what Pest's own `Graph::shouldRerunStatus()`
(`/Users/jason/workspace/pest/src/Plugins/Tia/Graph.php:709-762`) already has to account for — it
checks `$configuration->failOnSkipped()` / `failOnRisky()` / etc. before
deciding a cached non-success status is even safe to replay. Port that same
policy check into `RunWithTia`, just applied to the *skip* decision instead
of a replay decision.

---

## 4. Component design

### 4.1 Recording — piggyback on PHPUnit's own coverage, don't drive pcov/xdebug yourself

Pest supports two recording modes because Pest can run *without* any
coverage report requested at all (`/Users/jason/workspace/pest/src/Plugins/Tia/Recorder.php` drives
pcov/xdebug manually in that case). A PHPUnit extension doesn't have that
problem: `PHPUnit\Runner\Extension\Facade::requireCodeCoverageCollection()`
(`/Users/jason/workspace/blueprint/vendor/phpunit/phpunit/src/Runner/Extension/Facade.php:84`) lets an extension *force* PHPUnit
to collect coverage for the whole run, regardless of CLI flags. Call it once
in `Extension::bootstrap()` and PHPUnit handles driver selection
(pcov/xdebug/pcovwrapper) itself.

That means v1 only needs the piggyback path — reimplement
`/Users/jason/workspace/pest/src/Plugins/Tia/CoverageCollector.php`, not `Recorder.php`'s manual
pcov/xdebug driving. Its whole job:

```php
$lineCoverage = PHPUnit\Runner\CodeCoverage::instance()
    ->codeCoverage()->getData()->lineCoverage();
// array<sourceFile, array<line, list<testId>|null>>
```

Invert that (line → test IDs) into (test file → source files), by resolving
each `testId` (`"Fully\Qualified\Class::method"`) back to the file that
defines the class (`ReflectionClass::getFileName()` — vanilla-PHPUnit tests
are real classes/methods, so this is actually *simpler* than Pest's version,
which has to unwind Pest's dynamically-generated `__filename` static
property, `/Users/jason/workspace/pest/src/Plugins/Tia/CoverageCollector.php:100-109`).

Read this once at the end of the run (`TestRunner\ExecutionFinished` event or
equivalent), not per-test — `getData()->lineCoverage()` already has the
per-line test-id attribution baked in by PHPUnit's own coverage collector.

**Open question to verify when building**: does
`requireCodeCoverageCollection()` hard-fail if no driver (pcov/xdebug) is
installed at all, or does it silently no-op? If it hard-fails, `bootstrap()`
needs its own driver-presence check first (`function_exists('pcov\\start')` /
`xdebug_info()`) and should degrade to "extension disabled this run, warn
once" rather than breaking the suite — same posture as
`/Users/jason/workspace/pest/src/Plugins/Tia/Recorder.php:68-87`'s `driverAvailable()`.

### 4.2 Change detection — port `ChangedFiles.php` + `ContentHash.php` almost verbatim

`/Users/jason/workspace/pest/src/Plugins/Tia/ChangedFiles.php` is pure `Symfony\Process` + git
plumbing (`git diff --name-only`, `git status --porcelain -z`,
`git check-ignore`, `git merge-base --is-ancestor`) with zero Pest/PHPUnit
coupling. Same for `ContentHash.php` (xxh128 content hashing, used instead of
mtime specifically so CI checkouts with fresh mtimes don't false-positive
every file as changed). Copy the approach directly; no vanilla-PHPUnit
adaptation needed.

Keep the two key behaviors that aren't obvious from the method names:
- `filterUnchangedSinceLastRun()` — a "last run tree" snapshot (path→hash) so an uncommitted file that hasn't actually changed *since the last recorded run* doesn't get re-flagged every single invocation.
- `filterBehaviourallyUnchanged()` — after `git diff --name-only`, re-hash current content against `git show <sha>:<path>` content, because a file can appear in the diff (e.g. touched then reverted, or a merge) without its final content differing from the baseline.

### 4.3 The graph & impact analysis — port `Graph.php`'s core, drop the Laravel-specific heuristics into a `Resolver` interface

`/Users/jason/workspace/pest/src/Plugins/Tia/Graph.php` is ~1500 lines but only a fraction is
generic. Split it like this:

**Core (port to `phpunit-tia`)**:
- The bipartite edge map (test file → source file IDs) + `link()`/`replaceEdges()` (`Graph.php:66-82, 813-830`).
- `affected(array $changedFiles)` orchestration shape (`Graph.php:88-117`) — but drop the migration/Inertia/Blade steps, keep: direct edge lookup, "a changed test file is its own unit of work" (`applyTestFileChanges`, `Graph.php:414-430`), and the sibling-directory fallback for files with zero coverage edges (new/never-covered files) — generalize `usesSiblingHeuristicForUnknownPhp`'s prefix list (`Graph.php:962-992`) into a `Resolver`-supplied list instead of hardcoding Laravel paths.
- Per-branch baselines (sha + last-run-tree + results), `setResult`/`getResult`/`shouldRerunStatus` (`Graph.php:592-762`) — needed by the trait to decide replay-vs-run.
- `pruneMissingTests()` / `pruneStaleResults()` (`Graph.php:1357-1423`) — housekeeping for deleted/renamed tests.
- JSON `encode()`/`decode()` with a `schema` version field (`Graph.php:1425-1501`) so a stale on-disk format from an older package version gets discarded, not misread.

**Extension point instead of hardcoded (`Contracts/Resolver.php`, new)**:
```php
interface Resolver
{
    /** Called with every changed path PHP couldn't map to a known source edge. */
    public function resolve(string $projectRoot, string $changedRelativePath): array; // list<string> affected test files, or []
}
```
A `laravel/phpunit-tia-resolver` (or similar) package can later implement
migration→table→test (`Graph.php:150-193` + `TableExtractor.php`), Blade
static-include walking (`Graph.php:1102-1241`), Inertia page→component
resolution (`Graph.php:201-347` + `JsModuleGraph.php`) — all as one or more
`Resolver`s registered via the same extension's parameter collection. None of
that belongs in core.

**Also drop for v1** (Pest-specific, no vanilla-PHPUnit equivalent):
`isArchTestFile()`/`archTestFiles()` (`Graph.php:1004-1087`, Pest's `arch()`
tests are a Pest-only concept).

### 4.4 Fingerprint — cache invalidation, port near-verbatim

`/Users/jason/workspace/pest/src/Plugins/Tia/Fingerprint.php` hashes `composer.lock`,
`phpunit.xml`/`phpunit.xml.dist`, PHP major version, and (Pest-specific,
drop) Vite/JS config. `structuralMatches()` decides "must rebuild the graph
from scratch" (e.g. composer.lock changed → autoloaded classes might have
moved); `environmentalDrift()` decides "keep edges, drop cached
pass/fail results" (e.g. PHP version changed — coverage edges are probably
still valid but a previously-passing test might behave differently). Keep
both tiers; this distinction is what stops a routine `composer update` from
forcing a full slow re-run *and* stops a genuinely different PHP version from
silently replaying stale results.

Bump a `SCHEMA_VERSION` constant (`Fingerprint.php:15`) whenever the graph's
on-disk shape changes during development — cheap insurance against reading a
graph written by a previous dev iteration of this package.

### 4.5 Storage — where the graph lives

`/Users/jason/workspace/pest/src/Plugins/Tia/Storage.php` computes `~/.pest/tia/<project-slug>-<hash-of-git-origin-or-path>/`
— i.e. **outside the repo, keyed by git remote**, specifically so CI runners
with ephemeral checkouts and multiple clones of the same repo (worktrees,
multiple CI agents) share one cache location and nobody needs a `.gitignore`
entry. Worth copying this pattern rather than defaulting to
`<project>/.phpunit-tia/` — the origin-URL keying is what makes it safe to
point every clone/worktree of the same repo at the same cache without
collisions or the graph leaking into commits by accident.

`Contracts/State.php` (`read`/`write`/`delete`/`exists`/`keysWithPrefix`) is
a clean minimal interface — port as-is, gives you a seam to swap in a
different backend (e.g. a shared filesystem cache in CI) later without
touching callers.

### 4.6 Result collection — subscribe to PHPUnit's own test-result events

`/Users/jason/workspace/pest/src/Plugins/Tia/ResultCollector.php`'s shape (status/message/time/assertions
per test ID) is exactly what you'd build from PHPUnit's own
`PHPUnit\Event` subscribers — `Test\PassedSubscriber`, `Test\FailedSubscriber`,
`Test\ErroredSubscriber`, `Test\SkippedSubscriber`, `Test\MarkedIncompleteSubscriber`,
`Test\ConsideredRiskySubscriber`, `Test\PreparedSubscriber` (for start-time),
`Test\FinishedSubscriber` (for teardown/cleanup) — all registered via
`Facade::registerSubscribers()` in `Extension::bootstrap()`. This is the
*one* piece of Pest's TIA implementation that's already written against the
exact same public API you'll use — `/Users/jason/workspace/pest/src/Subscribers/EnsureTiaStarts.php`
and `EnsureTiaEnds.php` are ~15-line `PreparedSubscriber`/`FinishedSubscriber`
implementations; model the new subscribers directly on that shape.

### 4.7 The trait

```php
trait RunWithTia
{
    protected function setUp(): void
    {
        $graph = Tia::graph(); // loaded once per process, static/shared
        $status = $graph?->cachedStatusIfUnaffected(static::class, $this->name());

        if ($status !== null && !$graph->shouldRerunStatus($status, Registry::get())) {
            $this->addToAssertionCount($graph->cachedAssertionCount(static::class, $this->name()));
            $this->markTestSkipped(sprintf('TIA: unaffected, last run %s', $status->asString()));
        }

        parent::setUp(); // only reached when not replaying
    }
}
```

Mirrors the decision Pest makes in `Concerns/Testable.php:281-297`
(`getStatus()` → `ReplayType::fromStatus()` → branch), just collapsed to the
one outcome vanilla PHPUnit allows (skip) instead of Pest's four
(pass/skip/incomplete/failure-replay). Don't try to replay a *cached
failure* by throwing a fake `AssertionFailedError` from `setUp()` — that's
achievable mechanically (same trick) but actively unhelpful: a fresh failure
needs a fresh stack trace/diff, not a stale cached message. Only replay
(as skip) on a cached **pass**; always let risky/failure/error/incomplete
tests actually re-run. That's a deliberate simplification vs. Pest's
`ReplayType`, not an oversight — re-check this decision once real users hit
it, since Pest clearly decided the extra replay states were worth it for
something.

---

## 5. Data model (v1 graph JSON)

Simplified from `/Users/jason/workspace/pest/src/Plugins/Tia/Graph.php:1485-1501` — drop
`test_tables`/`test_inertia_components`/`js_file_to_components` (those move
to whatever a future `Resolver` package persists, likely via its own
namespaced key in the same `State` store rather than bloating this schema):

```json
{
  "schema": 1,
  "fingerprint": { "structural": {...}, "environmental": {...} },
  "files": ["app/Foo.php", "app/Bar.php"],
  "edges": { "tests/FooTest.php": [0, 1] },
  "baselines": {
    "main": {
      "sha": "abc123",
      "tree": { "app/Foo.php": "xxh128hash" },
      "results": {
        "Tests\\FooTest::test_it_works": { "status": 0, "message": "", "time": 0.012, "assertions": 3, "file": "tests/FooTest.php" }
      }
    }
  }
}
```

---

## 6. Verified PHPUnit surface

Everything below was confirmed by reading `phpunit/phpunit` 12.x source
directly (not assumed from docs), since a design built on a guessed API is
worse than no design.

- **Registration**: `phpunit.xml` `<extensions><bootstrap class="Vendor\Extension"><parameter name="k" value="v"/></bootstrap></extensions>`. Parsed into `Configuration::extensionBootstrappers()` (`/Users/jason/workspace/blueprint/vendor/phpunit/phpunit/src/TextUI/Configuration/Configuration.php:869`) and instantiated by `ExtensionBootstrapper::bootstrap()` (`/Users/jason/workspace/blueprint/vendor/phpunit/phpunit/src/Runner/Extension/ExtensionBootstrapper.php:41-83`) — reflection-instantiated with **no constructor arguments**, so all config must arrive via the `ParameterCollection` passed to `bootstrap()`, not the constructor.
- **`Extension` interface** (`/Users/jason/workspace/blueprint/vendor/phpunit/phpunit/src/Runner/Extension/Extension.php`): one method, `bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void`. Runs *before* `TestSuiteBuilder` builds the suite (`Application.php:140` vs `:182`) — but `Facade` has no method to influence *which* tests get built, only observation/coverage/output flags (confirmed by reading `Facade.php` in full — it's a 94-line class, every public method is listed in §6.1 below). This is why test *selection* (running only affected tests) is not achievable from inside the extension — see §8.
- **`Facade`** (`/Users/jason/workspace/blueprint/vendor/phpunit/phpunit/src/Runner/Extension/Facade.php`) — full public surface: `registerSubscribers()`, `registerSubscriber()`, `registerTracer()`, `replaceOutput()`, `replacesOutput()`, `replaceProgressOutput()`, `replacesProgressOutput()`, `replaceResultOutput()`, `replacesResultOutput()`, `requireCodeCoverageCollection()`, `requiresCodeCoverageCollection()`. That's the entire menu.
- **`TestCase`** constraints — see §3 for the full argument; the short version: `run()`/`runBare()` are `final`, `runTest()` is `private` and calls the real method directly, `setUp()` is the only open hook, `addToAssertionCount()` is `final public` (callable from a trait).
- **Coverage read-back**: `PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->getData()->lineCoverage()` — confirmed shape and usage via `/Users/jason/workspace/pest/src/Plugins/Tia/CoverageCollector.php:25-33`, which already uses this exact public API (Pest's piggyback mode, not its driven mode).

---

## 7. Config surface (v1)

`phpunit.xml`:
```xml
<extensions>
    <bootstrap class="Vendor\PhpunitTia\Extension">
        <parameter name="storage" value="global"/>  <!-- global (~/.phpunit-tia) | local (.phpunit-tia, gitignored) -->
    </bootstrap>
</extensions>
```

PHPUnit's `ParameterCollection` is flat string k/v — fine for the two or
three knobs above, but don't try to jam a `Resolver` list or complex config
through it. Support an optional `phpunit-tia.php` (or reuse `Resolver`
registration via `composer.json` `extra`) that returns a config array, loaded
in `bootstrap()`, for anything beyond flat strings — same reasoning Pest had
for offering `Configuration::watch()` as a fluent PHP API
(`/Users/jason/workspace/pest/src/Plugins/Tia/Configuration.php`) rather than trying to express
glob-pattern maps as CLI flags.

Env var escape hatches worth having from day one (mirrors
`/Users/jason/workspace/pest/src/Plugins/Tia.php:59-65`'s `PEST_TIA`/`PEST_TIA_FILTERED`/etc.):
`PHPUNIT_TIA=0` to hard-disable (CI full-suite runs, release branches),
`PHPUNIT_TIA_FRESH=1` to force a full rebuild (mirrors Pest's `--fresh`).

---

## 8. v2 candidates (explicitly deferred, don't build yet)

- **`--filter`-based selection wrapper**: a `vendor/bin/phpunit-tia` binary that computes the affected set the same way, then re-execs real `phpunit --filter '<computed regex>'`. This is a legitimate complement to the trait (skips the process-level cost of even instantiating unaffected `TestCase`s, not just their bodies) but is mechanically a wrapper trick, not an extension capability — see §6's confirmation that `Facade` has no test-selection hook. Keep it a separate bin/package so the core extension+trait stays usable standalone.
- **Parallel runner (ParaTest) support** — needs the worker/partial-merge machinery Pest has in `Tia.php` (`handleWorker`, `flushWorkerPartial`, `collectWorkerEdgesPartials`, `mergeWorkerReplayPartials`); real complexity, don't take on until single-process v1 is solid.
- **Remote baseline sync** (`/Users/jason/workspace/pest/src/Plugins/Tia/BaselineSync.php`) — fetching a pre-warmed graph from CI cache/artifact storage so a fresh clone doesn't start cold. Useful, not blocking.
- **Framework `Resolver` packages** (Laravel migrations/Blade/Inertia, Symfony, etc.) — the extension point ships in v1 (§4.3), the packages themselves are separate deliverables.
- **Replaying cached failures** — considered and rejected for v1 in §4.7; revisit only with a concrete case for it.

---

## 9. Reference index — concept → source

| Concept | Pest reference (this repo) | Notes for the port |
|---|---|---|
| Orchestration / mode decisions | `/Users/jason/workspace/pest/src/Plugins/Tia.php` | Skim for the decision tree (record vs. replay vs. rebuild), don't port the parallel/worker code |
| Per-test coverage → edges (piggyback) | `/Users/jason/workspace/pest/src/Plugins/Tia/CoverageCollector.php` | Port ~directly, this is the only recording mode v1 needs |
| Per-test coverage → edges (driven pcov/xdebug) | `/Users/jason/workspace/pest/src/Plugins/Tia/Recorder.php` | Skip for v1 (§4.1); revisit only if `requireCodeCoverageCollection()` proves insufficient |
| Git diff + content hashing | `/Users/jason/workspace/pest/src/Plugins/Tia/ChangedFiles.php`, `/Users/jason/workspace/pest/src/Plugins/Tia/ContentHash.php` | Port ~directly, zero framework coupling |
| Graph + `affected()` | `/Users/jason/workspace/pest/src/Plugins/Tia/Graph.php` | Port core (§4.3), extract Laravel/Blade/Inertia bits behind `Resolver` |
| Cache-invalidation fingerprint | `/Users/jason/workspace/pest/src/Plugins/Tia/Fingerprint.php` | Port directly, drop JS/Vite-specific fields |
| On-disk graph location | `/Users/jason/workspace/pest/src/Plugins/Tia/Storage.php` | Port the origin-URL-keyed global cache pattern |
| State read/write abstraction | `/Users/jason/workspace/pest/src/Plugins/Tia/Contracts/State.php`, `/Users/jason/workspace/pest/src/Plugins/Tia/FileState.php` | Port directly |
| Test result capture | `/Users/jason/workspace/pest/src/Plugins/Tia/ResultCollector.php` | Rebuild via `PHPUnit\Event` subscribers (§4.6) |
| Event-subscriber pattern | `/Users/jason/workspace/pest/src/Subscribers/EnsureTiaStarts.php`, `/Users/jason/workspace/pest/src/Subscribers/EnsureTiaEnds.php` | Already written against the exact public API you'll use — closest 1:1 template in the whole codebase |
| Replay decision enum | `/Users/jason/workspace/pest/src/Plugins/Tia/Enums/ReplayType.php` | Collapse to a bool (replay-as-skip or not) per §4.7 |
| The setUp()-hijack trick | `/Users/jason/workspace/pest/src/Concerns/Testable.php:281-397` | **Don't port the mechanism** (it relies on Pest owning `runTest()`) — port the *policy* (`shouldRerunStatus`) and reimplement the mechanism per §3/§4.7 |
| `shouldRerunStatus` fail-on-* policy check | `/Users/jason/workspace/pest/src/Plugins/Tia/Graph.php:709-762` | Port directly — needed so the trait doesn't skip a test a strict CI config would want re-run |

---

## 10. Suggested milestones

1. `Extension::bootstrap()` that just calls `requireCodeCoverageCollection()` and dumps `lineCoverage()` shape to a file — validates §4.1's open question before anything else is built on top of it.
2. `ChangedFiles` + `ContentHash` + `Fingerprint`, unit-tested against a scratch git repo — no PHPUnit coupling, can be built/tested in isolation first.
3. `Graph` core (edges, `affected()` without any `Resolver`, baselines, `encode`/`decode`) + `ResultCollector` via event subscribers — wire into the `Extension`, confirm a graph gets written after a real run.
4. `RunWithTia` trait against the graph from step 3 — confirm skip-with-cached-assertions behaves correctly under `--fail-on-skipped` both on and off.
5. `Resolver` interface + one throwaway example resolver (even something trivial) to prove the extension point is actually usable before any real framework package builds on it.
