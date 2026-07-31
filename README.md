# PHPUnit TIA
This is a port of Pest's new [Test Impact Analysis (TIA) Engine](https://pestphp.com/docs/tia). TIA greatly improves test suite performance by only running tests which relate to impacted (changed) files. This plugin brings the same performance improvements to PHPUnit.

**Note:** as a third-party plugin, the actual test runner can not be changed. Instead of preserving the previous state, unimpacted tests are marked as `skipped` (`S`). Suites running `--fail-on-skipped` (or `--display-skipped`) don't need to opt out manually — TIA detects this and automatically falls back to actually running the test instead of replaying a skip that policy would flag as CI-red.

## Installation

This plugin requires PHPUnit 13 and PHP 8.4, as well as a code coverage driver ([pcov](https://github.com/krakjoe/pcov) or [Xdebug](https://xdebug.org/) in `coverage` mode) to record new coverage. If you are not running PHPUnit 13, you may use [Shift to automate the upgrade](https://laravelshift.com/upgrade-phpunit-13).

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

## Usage
To enable TIA, add the trait to your base `TestCase`:

```php
use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use RunWithTia;
}
```

This will activate TIA for every `phpunit` invocation — unaffected tests will be
skipped automatically.

To bypass TIA, you may pass an environment variable at runtime:

```sh
PHPUNIT_TIA=0 phpunit ...
```

While a baseline will be established automatically, you may pass an environment variable to rebuild:

```sh
PHPUNIT_TIA_FRESH=1 phpunit ...
```

## Extending TIA with a `Resolver`

Core only knows how to connect a changed file to a test through actual code
coverage (plus a same-directory fallback for files nothing has covered yet).
Some changes — a database migration, a Blade template, a config file — affect
tests without ever being `require`d by one. A `Resolver` lets you teach TIA
about those cases:

```php
use JMac\Testing\PhpUnit\Tia\Contracts\Resolver;

final class MigrationResolver implements Resolver
{
    public function resolve(string $projectRoot, string $changedRelativePath): array
    {
        if (! str_starts_with($changedRelativePath, 'database/migrations/')) {
            return [];
        }

        return ['tests/Feature/WidgetTest.php'];
    }
}
```

Register it in an optional `phpunit-tia.php` file in your project root
(PHPUnit's own `phpunit.xml` `<parameter>` tags are flat strings and can't
express a list of classes):

```php
<?php

return [
    'resolvers' => [
        MigrationResolver::class,
        // or an already-constructed instance, if it needs dependencies
    ],
];
```

`resolve()` is called once per changed file core couldn't map to a known
source edge; return the affected test files, or `[]` if this resolver has no
opinion on that path.
