# twopayment test suite

This folder contains deterministic tests for order-building and payload safety logic.

## What is covered

- Line item formula validation (`tax_amount = net_amount * tax_rate`, exact `gross = net + tax`, net formula)
- Tax subtotal grouping and decimal tax-rate precision retention
- Declared-rate relay: the address-correct rate is used and the country-only
  `$line_item['rate']` field is ignored; a non-canonical declared rate is relayed as-is;
  a declared rate that diverges from the applied amounts throws
- Non-integer VAT handling for line-item formula safety (e.g. 5.5%)
- Guardrails that reject invalid line items before building order payloads
- Snapshot hash sensitivity to tax-rate precision changes beyond two decimals
- Gift wrapping payload line composition and reconciliation safety
- `PS_ATCP_SHIPWRAP` shipping split across the cart's canonical product rate classes
- Free-shipping discount gross re-derivation when the net cap bites
- High-quantity lines staying inside `NET_FORMULA_TOLERANCE`
- Currency compatibility gating for payment option visibility
- Large rounded discount split handling keeps tax-formula validation stable
- Cart-rule monetary (`value_real`/`value_tax_exc`) discount line attribution
- Tracking number sourcing (order_carrier vs legacy shipping_number) and the admin tracking-update hook
- Partial refunds via credit slips: amount+currency payload, slip-ID idempotency key, remaining-balance guard, and duplicate-refund suppression
- Default shipping tax code: hidden-unless-activated admin field, no-default-value refusal parity, save-while-hidden never wiping the stored selection, carrier-wins resolution order, and the README/code drift guard on the activation constant

`DefaultShippingTaxCodeSpec` **must stay last** in `run.php`: it `define()`s
`_TWO_ENABLE_DEFAULT_SHIPPING_TAX_CODE_` partway through its own run and a PHP
constant cannot be undefined again.

## Why this matters

These tests protect payment-critical invariants:
- Two payloads must reflect PrestaShop totals exactly
- Tax math must remain internally consistent
- Small precision regressions must not silently alter snapshot/idempotency behavior

## Run tests (offline)

From module root:

```bash
make test              # in the CI container (preferred)
php tests/run.php      # directly, if you have a local PHP
```

A new `tests/*Spec.php` file must also be added to the `require` list at the bottom of
`tests/run.php` — the runner does not glob.

## Playwright checkout suite

`tests/e2e/` drives a real checkout against a provisioned PrestaShop container; CI runs it
on the PrestaShop 8 and 9 images (`.github/workflows/e2e.yml`).

## Real-engine integration matrix

Integration coverage requirements for PrestaShop `1.7.8`, `8.x`, and `9.x` live in:

- `tests/integration/README.md`

This matrix validates cart-rule/tax/discount parity against real PrestaShop checkout/cart behavior, beyond the offline deterministic harness.

## When to add tests

Add or update tests when you change:
- Tax rate sourcing or the divergence assertions
- Order line item/net/gross/tax formulas
- Shipping/discount payload composition
- Snapshot or idempotency-sensitive hash inputs
