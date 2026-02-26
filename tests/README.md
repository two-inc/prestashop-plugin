# twopayment test suite

This folder contains deterministic tests for order-building and payload safety logic.

## What is covered

- Line item formula validation (`tax_amount = net_amount * tax_rate` and net formula)
- Tax subtotal grouping and decimal tax-rate precision retention
- Product tax-rate derivation when configured and applied rates differ
- Order-level tax-rate derivation from final net/tax totals
- Guardrails that reject invalid line items before building order payloads
- Snapshot hash sensitivity to tax-rate precision changes beyond two decimals

## Run tests (offline)

```bash
php tests/run.php
```

## Optional PHPUnit setup (if network access is available)

```bash
composer install
composer test
```

PHPUnit config and equivalent test file are included:

- `phpunit.xml.dist`
- `tests/OrderBuilderTest.php`
