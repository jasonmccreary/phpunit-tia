# PHPUnit TIA
This is a port of Pest's new [Test Impact Analysis (TIA) Engine](https://pestphp.com/docs/tia). TIA greatly improves test suite performance by only running tests which relate to impacted (changed) files. This plugin brings the same performance improvements to PHPUnit.

**Note:** as a third-party plugin, the actual test runner can not be changed. Instead of preserving the previous state, unimpacted tests are marked at "skipped" (`S`). This will affect test runs using the `--fail-on-skipped` option.

## Installation

Requires PHP 8.4 and PHPUnit 13.

```
composer require --dev jasonmccreary/phpunit-tia
```

Register the extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="JMac\Testing\PhpUnit\Tia\Extension">
        <parameter name="storage" value="global"/> <!-- global (~/.phpunit-tia) | local (.phpunit-tia, gitignored) -->
    </bootstrap>
</extensions>
```

Add the trait to your base `TestCase`:

```php
use JMac\Testing\PhpUnit\Tia\Traits\RunWithTia;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use RunWithTia;
}
```

## Usage

That's it — there's nothing else to run. Once the extension and trait are
wired up, TIA is active on every `phpunit` invocation: unaffected tests are
skipped automatically, no flag required.

Two env vars cover the rest:

- `PHPUNIT_TIA=0` — disable for this run (e.g. CI full-suite runs, release branches)
- `PHPUNIT_TIA_FRESH=1` — ignore the cached graph and rebuild from scratch
