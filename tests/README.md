# twopayment test suite

This folder contains deterministic tests for order-building and payload safety logic.

## What is covered

- Line item formula validation (`tax_amount = net_amount * tax_rate` and net formula)
- Tax subtotal grouping and decimal tax-rate precision retention
- Product tax-rate derivation when configured and applied rates differ
- Non-integer VAT handling for line-item formula safety (e.g. 5.5%)
- Order-level tax-rate derivation from final net/tax totals
- Guardrails that reject invalid line items before building order payloads
- Snapshot hash sensitivity to tax-rate precision changes beyond two decimals
- Gift wrapping payload line composition and reconciliation safety
- Currency compatibility gating for payment option visibility
- Large rounded discount split handling keeps tax-formula validation stable
- Cart-rule monetary (`value_real`/`value_tax_exc`) discount line attribution

## Why this matters

These tests protect payment-critical invariants:
- Two payloads must reflect PrestaShop totals exactly
- Tax math must remain internally consistent
- Small precision regressions must not silently alter snapshot/idempotency behavior

## Run tests (offline)

```bash
php tests/run.php
```

## Recommended pre-commit checks

From module root:

```bash
php -l twopayment.php
php tests/run.php
```

If you touched additional PHP files, lint them too:

```bash
php -l path/to/file.php
```

## Optional PHPUnit setup (if network access is available)

```bash
composer install
composer test
```

PHPUnit config and equivalent test file are included:

- `phpunit.xml.dist`
- `tests/OrderBuilderTest.php`

## When to add tests

Add or update tests when you change:
- Tax derivation logic
- Order line item/net/gross/tax formulas
- Shipping/discount payload composition
- Snapshot or idempotency-sensitive hash inputs
