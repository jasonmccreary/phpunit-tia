<p align="right">
    <a href="https://github.com/jasonmccreary/phpunit-tia/actions/workflows/tests.yml"><img src="https://github.com/jasonmccreary/phpunit-tia/workflows/tests/badge.svg" alt="Build Status"></a>
    <a href="https://packagist.org/packages/jasonmccreary/phpunit-tia"><img src="https://poser.pugx.org/jasonmccreary/phpunit-tia/v/stable.svg" alt="Latest Stable Version"></a>
    <a href="https://github.com/jasonmccreary/phpunit-tia/blob/main/LICENSE"><img src="https://poser.pugx.org/jasonmccreary/phpunit-tia/license.svg" alt="License"></a>
</p>


# PHPUnit TIA
This is a port of Pest's new [TIA Engine](https://pestphp.com/docs/tia). Test Impact Analysis (TIA) greatly improves test suite performance by only running tests which relate to impacted (changed) files. This extension brings the same performance improvements to PHPUnit.

## Installation
This extension requires PHPUnit 13 and PHP 8.4, as well as a code coverage driver ([pcov](https://github.com/krakjoe/pcov) or [Xdebug](https://xdebug.org/) in `coverage` mode) to record new coverage. If you are not running PHPUnit 13, you may use [Shift to automate the upgrade](https://laravelshift.com/upgrade-phpunit-13).

**Note:** TIA automatically tracks which files each test exercises, but for large suites, we recommend `php-code-coverage` 14.3+ for lower memory use during coverage collection.

```
composer require --dev jasonmccreary/phpunit-tia
```

Next, register the extension in your PHPUnit configuration:

```xml
<extensions>
    <bootstrap class="JMac\Testing\PhpUnit\Tia\Extension">
        <parameter name="storage" value="global"/>
    </bootstrap>
</extensions>
```


When the current branch has no baseline of its own, TIA falls back to `main`
by default. To use another branch, add the `fallback-branch` parameter:

```xml
<parameter name="fallback-branch" value="develop"/>
```

The fallback branch is used only for reading cached results, the recorded
commit, and the last-run tree. New results are still recorded under the
current branch.

## Usage
To enable TIA, add the trait to your base `TestCase`:

```php
use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use RunWithTia;
}
```

This will activate TIA for every `phpunit` invocation. Since third-party extensions can not change the PHPUnit test runner, TIA marks unimpacted tests as _skipped_ (`S`) to achieve faster replay speeds.

**Note:** if your `TestCase` already declares `setUp()`, you will need to
alias and call the trait's `setUp` explicitly:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use RunWithTia {
        RunWithTia::setUp as tiaSetUp;
    }

    protected function setUp(): void
    {
        $this->tiaSetUp();

        // ...your own setUp logic
    }
}
```

**Note:** if your `TestCase` declares `tearDown()`, you will need to guard any teardown
that depends on `setUp()`. A skipped test never reaches `setUp()`, but PHPUnit still runs
`tearDown()`. That teardown then errors, and the error replaces the skip:

```php
protected function tearDown(): void
{
    if (! $this->skippedByTia()) {
        // ...teardown that depends on setUp()
    }

    parent::tearDown();
}
```

To bypass TIA, you may pass an environment variable at runtime:

```sh
PHPUNIT_TIA=0 phpunit ...
```

While a baseline will be established automatically, you may pass an environment variable to rebuild:

```sh
PHPUNIT_TIA_FRESH=1 phpunit ...
```
## Additional Notes
There are a few additional notes to be aware of when using TIA.

### `--fail-on-skipped` and `--display-skipped`
Running tests with either option automatically bypasses TIA's speed boost. A skip TIA manufactures to represent an unaffected test would violate `--fail-on-skipped`, or surface as noise under `--display-skipped`, so TIA lets the test actually run instead. Drop these options to take full advantage of TIA.

### Parallel runs (ParaTest)
TIA records and replays under ParaTest just as it does in a single process. It relies on how ParaTest 7 bootstraps its parent and workers, and is tested against that major version. Each worker writes what it recorded to a file of its own, and the ParaTest parent merges those files into the graph once every worker has exited.

The baseline only advances when the parallel run was as complete as a single-process run has to be: every worker finished, none stopped mid-suite, and ParaTest did not stop handing out tests because of `--stop-on-failure`. Otherwise the merged results and edges are still kept, but the next run diffs against the previous baseline, and TIA says so on STDERR.

### Coverage targeting with `#[Covers*]` attributes
If your suite uses `#[CoversClass]`, `#[CoversMethod]`, or the other `#[Covers*]` attributes, PHPUnit only collects coverage for the class or method each one names. TIA relies on that coverage to know what a test depends on, so it can wrongly skip a test whose collaborator changed.

TIA warns on STDERR the first time this happens during a recording run — and any run without an established baseline counts as recording, not just an explicit `PHPUNIT_TIA_FRESH=1` rebuild. When you see that warning, add the option below to every run that records:

```sh
phpunit --disable-coverage-targeting
```

Pair it with `PHPUNIT_TIA_FRESH=1` if you're deliberately rebuilding a baseline. You only need the flag when recording — replaying a baseline, or running without a coverage driver, works fine without it.

### Run summary
Every run starts with one line on STDERR saying why TIA is inactive, or how many test files the changes affect. Test files with a recorded result that was not a pass run anyway; the `+` counts those:

```
phpunit-tia: 12 of 165 test files affected (+1 without a cached pass).
phpunit-tia: inactive: composer.lock/phpunit.xml changed since the stored graph was written.
```

With `PHPUNIT_TIA_DEBUG=1` (see below), the line also lists the changed files that affect the most test files:

```
phpunit-tia: 12 of 165 test files affected. By changed file: src/Models/Order.php (11), src/Services/Mailer.php (2), tests/OrderTest.php (1).
```

Running with `--fail-on-skipped` or `--display-skipped` reports TIA as inactive: it replays a cached pass as a skip, so under either option every test runs. ParaTest workers don't write it, as each would repeat it.

### Debugging a test that won't skip
If a test keeps running when you expect TIA to skip it, pass an environment variable to have TIA explain why on STDERR, one line per test that actually ran:

```sh
PHPUNIT_TIA_DEBUG=1 phpunit ...
```

```
TIA-DEBUG: running Tests\FooTest::test_it_works — source changed: src/Foo.php
```

A common cause: TIA's change detection includes `git status`, so any file a test run writes back into the project tree (a fixture database, a generated upload, a cache directory) looks "changed" on every run if it isn't `.gitignore`d — and can mark every test that shares its directory as affected. If the reported reason names a file you didn't intentionally edit, `.gitignore` it and re-run.

### Files coverage can't see
Coverage only records executed PHP inside `<source>`. A template a test renders, a fixture it reads or a migration it runs never gets an edge, so a change to one skips the tests that depend on it. A framework integration can link those files to the running test, and they then work like any covered source file:

```php
use JMac\Testing\PhpUnit\Tia\Tia;

// e.g. from a view composer or a query listener, while a test runs
Tia::link($view->getPath());
```

`Tia::link()` does nothing when the run doesn't record, or outside a running test.

A changed file without an edge (a new partial, a new migration) goes to the registered resolvers. A resolver that implements `Contracts\EdgeAwareResolver` is handed the graph, and can ask which tests are linked to a file it already knows (the template that includes the new partial, or an earlier migration of the same table), or for every test it knows, for a change such as a config file that affects all of them. Its answer is final: return `null` to leave the path to the resolvers after it and the sibling-directory guess.

```php
public function resolve(Edges $edges, string $projectRoot, string $changedRelativePath): ?array
{
    if ($changedRelativePath !== 'resources/views/partials/total.blade.php') {
        return null;
    }

    return $edges->testsLinkedTo('resources/views/invoice.blade.php');
}
```

## CI Workflows
To use TIA in CI, your baseline graph must persist between runs. See our own [GitHub Action workflow](.github/workflows/tests.yml) for an example. At a high level, your workflow needs to:

- Check out with full git history (`fetch-depth: 0`), since TIA diffs against a baseline commit
- Re-attach `HEAD` to the real branch name, since a detached `HEAD` collapses baselines across branches
- Cache TIA's storage directory (`~/.phpunit-tia` for `global` storage, or the configured path for `local`) keyed per-branch/runner, and save it after every run

**Note:** TIA is intended to reduce the feedback loop during development. As such, an ideal workflow is using TIA in local environments and running the full test suite in CI environments.

## Contributing
You may contribute by opening a Pull Request with your changes. All PRs should target `main`, include tests to verify your change, and pass the GitHub Action workflows.
