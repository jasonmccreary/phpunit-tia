# PHPUnit TIA
This is a port of Pest's new [Test Impact Analysis (TIA) Engine](https://pestphp.com/docs/tia). TIA greatly improves test suite performance by only running tests which relate to impacted (changed) files. This plugin brings the same performance improvements to PHPUnit.

**Note:** as a third-party plugin, the actual test runner can not be changed. Instead of preserving the previous state, unimpacted tests are marked at "skipped" (`S`). This will affect test runs using the `--fail-on-skipped` option.

## Installation

This plugin requires PHPUnit 13 and PHP 8.4. If you are not running PHPUnit 13, you may use [Shift to automate the upgrade](https://laravelshift.com/upgrade-phpunit-13).

```
composer require --dev jasonmccreary/phpunit-tia
```

Next, register the extension in your PHPUnit configuration:

```xml
<extensions>
    <bootstrap class="JMac\Testing\PhpUnit\Tia\Extension">
        <parameter name="storage" value="global"/> <!-- global (~/.phpunit-tia) | local (.phpunit-tia, gitignored) -->
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

This will activate TIA for every `phpunit` invocation - unaffected tests will be
skipped automatically.

To bypass TIA, you may pass an ENV at runtime:

```sh
PHPUNIT_TIA=0 phpunit ...
```

While a baseline will be established automatically, you may pass an ENV to rebuild:

```sh
PHPUNIT_TIA_FRESH=1 phpunit ...
```
