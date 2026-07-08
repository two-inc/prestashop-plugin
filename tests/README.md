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
- Tracking number sourcing (order_carrier vs legacy shipping_number) and the admin tracking-update hook (TWO-24762)
- Partial refunds via credit slips: amount+currency payload, slip-ID idempotency key, remaining-balance guard, and duplicate-refund suppression (TWO-24759)

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

## Real-engine integration matrix

Integration coverage requirements for PrestaShop `1.7.8`, `8.x`, and `9.x` live in:

- `tests/integration/README.md`

This matrix validates cart-rule/tax/discount parity against real PrestaShop checkout/cart behavior, beyond the offline deterministic harness.

## When to add tests

Add or update tests when you change:
- Tax derivation logic
- Order line item/net/gross/tax formulas
- Shipping/discount payload composition
- Snapshot or idempotency-sensitive hash inputs
