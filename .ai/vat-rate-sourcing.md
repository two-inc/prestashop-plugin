# VAT rate sourcing — how the order payload gets its tax rates

Scope: `twopayment.php` order-intent / order payload construction.

## The rule

**The plugin relays the merchant's declared tax rate. It never derives a rate from
amounts (`tax ÷ net`), never snaps one to a canonical value, never blends. We are not
the tax authority.**

A wrong-but-self-consistent declaration (merchant configured 21% and PrestaShop also
costed the line at 21%, where a reduced rate was legally correct) is relayed faithfully
and is the merchant's problem. An internally contradictory line (declared rate ≠ what
the amounts imply) cannot be represented faithfully, so it fails loud.

## Single rate source

`getTwoConfiguredTaxRateDecimalForGroup($taxRulesGroupId, $cart)` is the only rate
source for product, ecotax, shipping and wrapping lines. It resolves the rate through
the same core machinery PrestaShop's pricing uses — `TaxManagerFactory` over the cart's
`PS_TAX_ADDRESS_TYPE` address (delivery fallback), plus the shop-wide `PS_TAX` gate and
the vatnumber-module B2B exemption — so the rate is read at the **same address
granularity** PrestaShop used for the amounts.

- Never use `$line_item['rate']` from `getProducts(true)`: `Product::getTaxesInformations`
  builds a synthetic address with `id_state = 0` / `postcode = 0`, so it is **country-only**
  and diverges from the cart amounts for any sub-national zone.
- Group id `0`/unset returns `0.0` (core's "No tax" sentinel — ecotax and wrapping groups
  are legitimately unset on many shops). Resolution failures are logged **unconditionally**
  (not gated on `PS_TWO_DEBUG_MODE`) so a swallowed-to-zero rate shows its cause.

| Component | Declared-rate source |
|---|---|
| Product line | resolver, group via `Product::getIdTaxRulesGroupByIdProduct($id_product, $this->context)` |
| Ecotax line | resolver, `PS_ECOTAX_TAX_RULES_GROUP_ID` |
| Shipping line | `resolveTwoCartShippingRateClasses()` — the tax-rules groups the **selected delivery option** spans, not whichever `$cart->id_carrier` happens to load. More than one group → one `SHIPPING_FEE` line per rate, weighted by the per-carrier nets. No resolvable carrier group → see below. |
| Wrapping line | resolver, `PS_GIFT_WRAPPING_TAX_RULES_GROUP` |
| Discount line(s) | `buildTwoCanonicalDiscountRateSegments` / `solveTwoRateDiscountSplitInCents`; raises when unsolvable |
| Free-ship discount | mirrors the emitted rate of the shipping line it offsets, so the pair nets to zero |

When `PS_ATCP_SHIPWRAP` is on, PrestaShop taxes shipping and wrapping at the blended
average product rate. That rate is non-canonical and unacceptable downstream, so
`splitTwoChargeAcrossProductRateClasses()` apportions the charge across the cart's
canonical product rate classes instead. Any apportioned charge reconciles to the
PrestaShop-authoritative total by largest-remainder cent distribution
(`allocateTwoAmountByWeights`), or fails loud.

## Shipping with no resolvable carrier group

PrestaShop declares shipping VAT on the carrier row (`carrier_tax_rules_group_shop`) and
nowhere else — there is no shop-level shipping group. A shop pricing shipping outside the
carrier table (custom logistics, `id_carrier = 0`, which makes core discard the whole
delivery-option list) has no core row to declare it on. Resolution order, in
`resolveTwoCartShippingRateClasses()`:

1. the tax-rules group(s) the selected delivery option's carrier(s) declare;
2. `PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP` — the merchant's own declaration, moved onto
   the module (`resolveTwoDefaultShippingRateClasses()`). Consulted **only** on the path
   that would otherwise refuse, so a shop with a working carrier table never reaches it.
   Unset, or pointing at a since-deleted group, counts as **not declared**;
3. loud refusal.

This does not weaken the relay rule: step 2 is still a merchant declaration resolved
through the same helper, not a rate inferred from amounts. The whole charge goes into one
rate class — the per-carrier split of step 1 is unavailable by construction here.

Using step 2 logs at severity 2 naming the group, its id and the resolved rate, so "this
shop is on the fallback" is a log grep, not an inference. When a default is configured the
refusal log drops from severity 3 to 2, because the refusal is then internal control flow
rather than a failure.

The admin field renders on Order management like every other setting on that tab —
no build-time flag, no runtime gate. See the README's "Default shipping tax code" section
for the merchant-facing instructions.

## Divergence handling

`assertTwoDeclaredRateReconcilesWithAmounts()` runs per charge line: if
`|applied_tax − round(net × declared_rate, 2)| > TAX_FORMULA_TOLERANCE` (**0.02**) it logs
the declared rate, net, applied tax and expected tax at level 3, then throws
`TwoCheckoutAmountException`. All throw-reachable call sites — approval precheck, live
submit (`payment.php`), post-payment confirmation (`confirmation.php`) — catch and degrade
to a controlled decline; the actionable detail is a merchant/log artefact, the buyer sees a
generic notice.

`validateTwoLineItems()` then checks the **emitted 2dp** payload: `tax ≈ rate · net`
(`TAX_FORMULA_TOLERANCE`), exact `gross == net + tax` in cents, and
`net == qty · unit_price − discount` (`NET_FORMULA_TOLERANCE`).

## Rate precision

- `TAX_RATE_PERCENT_PRECISION = 2` — the operative ceiling, 2dp-of-percent, matching the
  Spanish e-invoicing limit. Preserves x.x5% rates.
- `TAX_RATE_PRECISION = 6` — `formatTwoTaxRate` precision. Must stay **≥ 4dp** so the
  normaliser's 4dp-of-decimal output survives formatting. `formatTwoTaxRate` strips
  trailing zeros (0.21 emits `"0.21"`); that is intentional.
- `SNAPSHOT_TAX_RATE_PRECISION = 2` — independent of the above, for refund/idempotency hash
  stability. Do not couple it to the emitted precision.

## Open follow-ups

- **`NET_FORMULA_TOLERANCE` is deliberately 0.05, not 0.02.** The emitted `unit_price` is
  2dp while the discount is derived at 6dp, so a legitimate high-quantity line can drift up
  to `qty · 0.005`. Tighten only after that absorption gap is closed; the high-quantity
  fixture in `tests/run.php` is the guard.
- **`tax_code` + exemption reason for 0-rate / exempt lines** — required for Spanish
  e-invoicing, not yet emitted. Tracked as Linear TWO-24877.
- **Compound tax groups** (US state+county, CA GST+PST, multi-`TaxRule` EU) have no single
  canonical declared rate. The PrestaShop-native answer is to iterate
  `TaxCalculator->taxes` and emit one component per constituent `Tax` rather than pick a
  rate. Not implemented; single-rate groups are exact today.
