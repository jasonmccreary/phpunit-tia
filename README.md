# PHPUnit TIA
This is a port of Pest's new [Test Impact Analysis (TIA) Engine](https://pestphp.com/docs/tia). TIA greatly improves test suite performance by only running tests which relate to impacted (changed) files. This plugin brings the same performance improvements to PHPUnit.

**Note:** as a third-party plugin, the actual test runner can not be changed. Instead of preserving the previous state, unimpacted tests are marked at "skipped" (`S`). This will affect test runs using the `--fail-on-skipped` option.
