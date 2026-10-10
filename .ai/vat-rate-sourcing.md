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

On the create path, `getTwoConfiguredTaxRateDecimalForGroup($taxRulesGroupId, $cart)`
is the only rate source for product, ecotax, shipping and wrapping lines (an order
update reads its rates from the stored order instead: see "Order updates" below). It resolves the rate through
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

## Order updates

An update (tracking save, back-office edit, the confirmation sync) carries the order
exactly as PrestaShop recorded it, and never the live catalogue, cart or config
(TWO-26085). The Two order it targets is the whole Two order: a multi-carrier cart is
split into one order per carrier sharing a reference, and every rate and amount below
is read across all of them.

| Component | Stored rate source |
|---|---|
| Product line | the `order_detail_tax` rates of its `order_detail` row (`order_detail.tax_rate` is 0 on 1.7), zero where the row carries no tax |
| Ecotax line | `order_detail.ecotax_tax_rate` |
| Shipping line | each order's `carrier_tax_rate`, against the shipping that order's paid total charged (`total_paid` less products and wrapping, plus discounts; PrestaShop 9 stores the whole cart's shipping in `total_shipping` on every order of a multi-carrier split). An order whose rate does not agree with its amounts (including a taxed shipping charge with no carrier rate recorded) fails loud; no rate is resolved from today's carriers or the module default. |
| Wrapping line | the `order_invoice_tax` rows of type `wrapping`, once the order is invoiced. No order column holds it before then, so until an invoice exists the configured `PS_GIFT_WRAPPING_TAX_RULES_GROUP` rate is used and must reconcile with the stored amounts. |
| Discount line(s) | the orders' `order_cart_rule` rows, split over the product lines only, as core computes a cart rule on the products |

`PS_ATCP_SHIPWRAP` is still read from config and splits shipping and wrapping over the
stored product rates.

When `PS_ATCP_SHIPWRAP` is on, PrestaShop taxes shipping and wrapping at the blended
average product rate. That rate is non-canonical and unacceptable downstream, so
`splitTwoChargeAcrossProductRateClasses()` apportions the charge across the cart's
canonical product rate classes instead. Any apportioned charge reconciles to the
PrestaShop-authoritative total by largest-remainder cent distribution
(`allocateTwoAmountByWeights`), or fails loud.

## Shipping no carrier provides a rate for

PrestaShop declares shipping VAT on the carrier row (`carrier_tax_rules_group_shop`) and
nowhere else — there is no shop-level shipping group. A shop pricing shipping outside the
carrier table (carrier-less shipping, `id_carrier = 0`, which makes core discard the whole
delivery-option list) has no core row to declare it on. `resolveTwoCartShippingRateClasses()`
decides where the rate comes from (TWO-26117):

1. a carrier in the selected delivery option that declares a tax-rules group **provides**
   the rate, an explicit 0% group included. It is relayed as is, with no module check;
2. "No tax" (group 0), no carrier, or a carrier that cannot be read provides **none**.
   `PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP` — the merchant's own declaration, moved onto
   the module (`resolveTwoDefaultShippingRateClasses()`) — then supplies it, and only this
   rate is checked against the line's tax. Unset, disabled, or pointing at a since-deleted
   group counts as **not declared**;
3. with neither, the line goes out at rate 0 with the tax it was charged, and Two's API
   judges it. The module never refuses on shipping tax unless step 2 applies.

This does not weaken the relay rule: step 2 is still a merchant declaration resolved
through the same helper, and step 3 relays the absence of one rather than inferring a
rate from amounts. The whole charge goes into one rate class — the per-carrier split of
step 1 is unavailable by construction here.

Placement records which case applied (`shipping_rate_provided` beside the declared classes
in the Two row), and updates and credit slips read that record rather than the carrier or
the setting as they are later.

Using step 2 logs at severity 2 naming the group, its id and the resolved rate, so "this
shop is on the fallback" is a log grep, not an inference. The missing-carrier log is a
severity 2 warning either way, since neither step 2 nor step 3 is a failure.

The admin field is hidden until Two enables it with `twopayment:shipping-tax-fallback`.
See the README's "Default shipping tax code" section for the merchant-facing instructions.

## Divergence handling

`assertTwoDeclaredRateReconcilesWithAmounts()` runs per charge line (on shipping, only for the
Default shipping tax code's rate, TWO-26117): if
`|applied_tax − round(net × declared_rate, 2)| > TAX_FORMULA_TOLERANCE` (**0.02**) it logs
the declared rate, net, applied tax and expected tax at level 3, then throws
`TwoCheckoutAmountException`. All throw-reachable call sites — approval precheck, live
submit (`payment.php`), post-payment confirmation (`confirmation.php`) — catch and degrade
to a controlled decline; the actionable detail is a merchant/log artefact, the buyer sees a
generic notice.

`validateTwoLineItems()` checks **emitted 2dp** line amounts: `tax ≈ rate · net`
(`TAX_FORMULA_TOLERANCE`, skipped on a shipping line whose rate is relayed unchecked), exact `gross == net + tax` in cents, and
`net == qty · unit_price − discount` (`NET_FORMULA_TOLERANCE`). Since TWO-26283 it guards only the module's own
buyer fee line while it is built (a quoted fee line that fails is left out; a placed fee that fails to replay
refuses the build). The payload as a whole is not checked for its own arithmetic: Two's API validates it.

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
