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
- Cart-vs-order-lines reconciliation diagnostics naming only the drifted figures (gross, net, tax)
- Line-item `image_url` sourced from the cart row's combination-aware `id_image`, empty when the product has no image
- Snapshot hash sensitivity to tax-rate precision changes beyond two decimals
- Gift wrapping payload line composition and reconciliation safety
- `PS_ATCP_SHIPWRAP` shipping split across the cart's canonical product rate classes
- Free-shipping discount gross re-derivation when the net cap bites
- High-quantity lines staying inside `NET_FORMULA_TOLERANCE`
- Currency compatibility gating for payment option visibility
- Buyer fee quote failure withholding the payment option at checkout, judged on the charged term only
- Large rounded discount split handling keeps tax-formula validation stable
- Cart-rule monetary (`value_real`/`value_tax_exc`) discount line attribution
- Buyer company resolution across the intent, create and update payloads: the invoice address wins, the shipping address is the fallback, and the name and organisation number always come from the same address
- Tracking number sourcing (order_carrier vs legacy shipping_number) and the admin tracking-update hook
- Order updates replaying the placed order (order lines, recorded taxes, the shipping each order charged, vouchers, invoiced wrapping rate) across every order of a multi-carrier split rather than the live cart, skipping an unchanged update, and leaving other payments alone
- Partial refunds via credit slips: amount+currency payload, slip-ID idempotency key, remaining-balance guard, and duplicate-refund suppression
- Default shipping tax code: no-default-value refusal parity, an omitted-field save never wiping the stored selection, the carrier-wins resolution order, and the TWO-26082 gate (field hidden and fallback ignored until the `twopayment:shipping-tax-fallback` console command enables it, globally or per shop, with an order following its cart's shop), and a check that the module's service files reference only parameters PrestaShop 1.7.6 defines in the container that loads them
- The order postprocessing hook (`OrderPostprocessingSpec`, TWO-26092): with no subscriber every builder's payload is byte-identical to a golden taken from the pre-hook builder (`fixtures/order-postprocessing-golden.json`); consistent hook edits are sent as returned; a throwing, non-array or unencodable subscriber fails the request with its one code; each shop-match check refuses with no merchant handler, stands down for one (with the log line naming it) and comes back through `runTwoShopMatchChecks()` (TWO-26274, with the update path in `PlacedOrderUpdateSpec`, the credit slip in `RefundSpec` and the fee parity in `SurchargeCartLineSpec`); which modules count as a merchant handler; each consistency check refusing after the hook with and without a handler; the API's error message reaches the log and the order; every request type fires exactly once and sends the post-hook payload; the `recomputeTwoOrderTotals()` helper; the Debug Mode diff; and a call-site allowlist that goes red when an order request is sent, or a builder stops firing the hook, outside the choke

- Tax codes on 0% lines (`TaxCodeSpec`, TWO-24877): one row per derivation rule (export outside the EU and to the Canaries, Ceuta and Melilla, intra-community, reverse charge, and the cases that derive nothing), Monaco counted as France, shipping following goods or services, ecotax, wrapping, buyer fee and discount lines (mapped and derived, the discount's shared code), shipping mapped by the delivery option's carriers or the Default shipping tax code, the delivery and buyer address fallbacks, the merchant country stored and refetched once, the code list's retry floor, the settings save, the mapping beating the derivation and applying to a non-Spanish merchant, updates sending placement's codes, a corrupt mapping read only for a 0% line, failing loud there and withholding the payment method at checkout, the code list hiding codes that need a reason, and byte-identical goldens (`fixtures/tax-code-golden.json`, written on the base branch) for the non-zero, non-Spanish and nothing-derived payloads
- Company-details postcodes fitted to the country's `zip_code_format` before the checkout copies them (`PostcodeFormatSpec`, TWO-26257), checked against core's `Country::checkZipCode()`
- Dev-mode service URL overrides (`TWO_API_BASE_URL`, `TWO_PORTAL_BASE_URL`,
  `TWO_CHECKOUT_BASE_URL`): each resolves independently of the other two, and every
  one of them is ignored unless `_PS_MODE_DEV_` is true

`TwoSoleTraderSpec` resolves those URLs in a **child PHP process**
(`tests/fixtures/dev-mode-url-probe.php`, invoked with `PROBE_PS_MODE_DEV=1|0|unset`):
`_PS_MODE_DEV_` is a constant, so one process cannot exercise both sides of the gate,
and the offline suite itself runs with the constant undefined.

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

## Browser JS suite (Jest)

`tests/js/` covers the module's front-office JavaScript under `views/js/`. CI gates it
(`.github/workflows/tests.yml`, job `jest`); locally it is `make test-js`.

```bash
make test-js           # installs devDeps when the lockfile moves, then npm run test:js
```

This target runs on the host, not in a container: it needs Node 20+ installed, the
same implicit prerequisite `bumpver` is for the version targets.

Unlike the PHP harness these tests glob, so a new `tests/js/*.test.js` file needs no
registration. See `tests/js/README.md` for how the harness stands the browser up.

## Playwright checkout suite

`tests/e2e/` drives a real checkout against a provisioned PrestaShop container; CI runs it
on the PrestaShop 1.7.6, 8 and 9 images (`.github/workflows/e2e.yml`).

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
