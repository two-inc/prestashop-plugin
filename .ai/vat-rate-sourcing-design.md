# PrestaShop plugin — VAT line-item & tax-rate sourcing redesign

**Status:** Draft for review
**Author:** Two Engineering
**Date:** 2026-06
**Scope:** `twopayment.php` (order-intent / order payload construction)
**Related:** tax-code / exemption-reason support — tracked separately as later work, out of scope here.

---

## 1. Problem

The plugin builds the Two order payload for Spanish (ES) merchants, and some intents are **rejected**. Three incremental fixes each addressed a different PrestaShop behaviour (ecotax-in-price, free-ship-zeroes-shipping, wrapping bucket). Seen together they point to **one underlying cause we can now address centrally**, rather than three independent quirks.

Loosening the payload to pass intent only defers failure to order creation / invoicing, on a live order — so we address the cause rather than the symptom.

### Worth stating plainly: the existing work was valuable, proactive engineering

Each of the three commits caught a genuine PrestaShop trap that would otherwise have shipped wrong totals or dropped/incorrect line items into production — with real customer impact. These were not papered-over symptoms; they were correct diagnoses of awkward PrestaShop behaviour:

- **Ecotax (`3411640`)** — PrestaShop folds ecotax *into* the product price (`PS_USE_ECOTAX`), which distorts the product's VAT context. Spotting that and decomposing ecotax into its own line, sourced from the dedicated **`ecotax_tax_rate` field**, is a genuinely non-obvious catch; without it the product VAT is silently wrong on every affected order.
- **Free-shipping (`0fa488b`)** — `getOrderTotal(ONLY_SHIPPING)` returns **0** whenever a free-shipping cart rule is active, a subtle trap that silently drops the shipping line. Recovering the real carrier cost via `getPackageShippingCost()` **and reading the carrier's own tax-rules-group rate** (`getIdTaxRulesGroup` → `TaxManagerFactory` → `getTotalRate`) is exactly the right instinct.
- **Wrapping (`fcbc793`)** — correctly identifying gift wrapping as a separate taxable bucket (`Cart::ONLY_WRAPPING`) and surfacing it as its own line.

Crucially, the shipping and ecotax fixes **already reach for the authoritative rate source** (the carrier tax-rules-group; the ecotax rate field) rather than inferring it — which is *precisely* the principle this redesign adopts across the board. **The redesign doesn't replace that instinct; it generalises it** so every line — products and discounts included — follows the same authoritative-source approach this work already proved out on the hardest PrestaShop quirks.

## 2. Responsibility boundary (the organising principle)

**Our job is to faithfully relay the merchant's declaration — net, gross, and the rate they configured — and nothing more. We are not the tax authority.**

Per line there are two merchant-derived numbers: the **declared rate** (the merchant's configured tax-rules-group rate) and the **arithmetical reality** (the applied amounts, `tax/net`). In a correctly-configured shop these are equal by construction (PrestaShop computes the amounts *by applying* the configured rate). Three cases:

1. **Declared rate == arithmetic** (normal, incl. a correctly-configured sub-national line): relay it, passes. Faithful.
2. **Wrong-but-self-consistent** (merchant configured 21% for a line PrestaShop also costed at 21%, even if a reduced rate was legally correct — e.g. a Canary Islands order, where IGIC at ~7% applies rather than mainland Spanish IVA at 21%): declared == arithmetic == 21%. We relay 21% faithfully; it passes; the wrong jurisdiction is **the merchant's problem**, invisible to us, *correctly so*. We must NOT silently "correct" it.
3. **Declared rate ≠ arithmetic** (rate says 21%, the amounts imply ~7%): internally contradictory — we cannot faithfully represent it → **fail loud.** Appropriate, and the merchant's to resolve.

**We never derive, snap, or substitute the rate.** Earlier work explored deriving the rate *from* the amounts (and snapping it toward canonical values) — a valid experiment for handling observed failure cases. Its side effect is that the rate matches the amounts by construction, so any divergence is absorbed rather than surfaced. We are now confident the underlying issue is best addressed by sending the declared rate directly: case 3 then surfaces loudly. Loud failure is appropriate **only** where declared ≠ arithmetic.

**The one obligation that remains ours:** read the declared rate at the **same address granularity** PrestaShop used for the amounts. If we misread it (e.g. a country-level rate against state-level amounts), we'd fail a *correctly-configured* order — that would be **our** bug, not the merchant's. See §4.1, §7.

## 3. What the Two API requires (behavioural)

The order payload is validated by the Two API. At the level the plugin must satisfy:

- **Canonical rates / codes.** For some countries (Spain included), each line's tax rate must be a canonical rate for that country; certain rates (notably zero / exempt) additionally require a tax code and exemption reason. Tax-code support is **separate later work** (tracked elsewhere) and out of scope here — for a standard-rated ES catalogue it is not required.
- **Arithmetic reconciliation.** Each line must reconcile: `tax ≈ rate · net` within a small tolerance, `gross == net + tax` exactly, and the line items must sum to the order total. This reconciliation may be enforced strictly per-merchant; the plugin should not rely on it being lenient.
- **Rate precision.** Spanish e-invoicing requires tax rates expressed to at most **2 decimal places of percent**.

**Implication:** the plugin must emit arithmetically-correct lines with canonical, faithfully-represented rates, and should **surface (fail loud) any internal contradiction itself** rather than depend on the API to catch it (the API's strict reconciliation may not be enabled for every merchant). The plugin already exposes a `PS_TWO_ENABLE_TAX_SUBTOTALS` toggle (twopayment.php:993) controlling whether tax subtotals are sent.

## 4. Target design

**Principle:** *Relay the merchant's declared rate + PrestaShop's amounts. Never derive (`tax÷net`), snap, or correct. The only plugin-side computation on the rate is reading it at the correct address granularity and asserting it reconciles with the amounts — else throw.*

### 4.1 Reading the declared rate at the right address granularity
Resolve the declared rate against the **same** address PrestaShop used to compute the amounts: `$cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}` (with a delivery fallback), exactly as `Cart.php` does.

**ESTABLISHED FACT (verified against PS core 1.6.1.24 / 1.7.8.11 / 8.1.7):** `$line_item['rate']` from `getProducts(true)` is **country-only** — `Product::getTaxesInformations` builds a synthetic address with `id_state = 0` and `postcode = 0`, so it ignores all sub-national tax rules. The cart *amounts* use the full `PS_TAX_ADDRESS_TYPE` address. They **diverge by construction** for any sub-national ES zone (the Canary Islands use IGIC, and Ceuta/Melilla use IPSI — distinct tax regimes from mainland Spanish IVA). This sub-national divergence is exactly what the amounts-derived approach happened to absorb — part of why it was a reasonable interim choice; resolving it at the source removes the need for it.

- **Always** source the product rate from the address-correct calculator. Do **not** use `$line_item['rate']` (country-only). **Helper signature:** the loop iterates `getProducts(true)` *arrays* — there is no `$product` object and the rows do **not** carry `id_tax_rules_group`. Resolve the group id explicitly per line via `Product::getIdTaxRulesGroupByIdProduct((int)$line_item['id_product'], $this->context->shop)` (**shop-aware** — the group is product+shop-scoped, not per-combination; combinations carry price/ecotax but not their own tax group). Then `getTwoConfiguredTaxRateDecimalForGroup($taxRulesGroupId, $cart)`.
- **Never** `tax÷net`.

**`PS_TAX_ADDRESS_TYPE` idiom:** the Configuration *value* string is `id_address_invoice` / `id_address_delivery` — identical to the Cart property names (`Cart.php:901` compares against `'id_address_invoice'`; `Cart.php:2484` uses it directly as `$this->{Configuration::get('PS_TAX_ADDRESS_TYPE')}`). No value-vs-property mapping needed.

Extract one shared helper `getTwoConfiguredTaxRateDecimalForGroup($taxRulesGroupId, $cart)` (address resolution via `PS_TAX_ADDRESS_TYPE` + `TaxManagerFactory::getManager` + `getTotalRate` + try/catch). Notes: (a) treat group id `0`/unset as "no rate" → return `0.0` (don't throw — ecotax/wrapping groups are legitimately unset on many shops); (b) **log any caught exception unconditionally** (not gated on `PS_TWO_DEBUG_MODE`) so a swallowed-to-0 rate shows its root cause (bad/deleted tax group) in the merchant log — otherwise an incident surfaces only as a downstream "formula mismatch" with no cause. `getPackageShippingCost()` already resolves tax via `PS_TAX_ADDRESS_TYPE`, so once the carrier rate goes through this helper, the shipping *rate* source matches the address used for the shipping *amount*.

### 4.2 Rate + amount sources
| Component | Rate source (declared, address-correct) | Amount source |
|---|---|---|
| Product line | shared helper (`$line_item['rate']` is country-only — §4.1) | `total` / `total_wt` |
| Ecotax line | `PS_ECOTAX_TAX_RULES_GROUP_ID` **via the shared helper** (`ecotax_tax_rate` key is absent on cart rows). Reconstruct ecotax_gross at this rate. | `ecotax` / `total_ecotax` |
| Shipping line | carrier group **via the shared helper** (not a snap candidate) | `getPackageShippingCost()` (real cost pre-free-ship) |
| Wrapping line | `PS_GIFT_WRAPPING_TAX_RULES_GROUP` **via the shared helper** | `Cart::ONLY_WRAPPING` |
| Discount line(s) | the exact-cent solver (`solveTwoRateDiscountSplitInCents`), pins `Σ segment_tax == row_tax`; segment rate = the offset class's declared rate | cart-rule values, net weight-allocated |
| Free-ship discount line | **mirror the emitted rate of the shipping line it offsets** (so the pair nets to zero) | offsets the shipping line — **re-derive gross from capped net** (§4.2.1) |

**4.2.1 Free-ship `min()` cap fix:** the current free-ship builder caps `alloc_net = min(alloc_net, discountNetTotal, alloc_gross)` (4284) *independently* of `alloc_gross`, so when the cap bites (any normal multi-discount cart) `alloc_tax = alloc_gross − alloc_net` no longer equals `rate·alloc_net` → the §4.3 assertion throws on a **legitimate** cart. Fix: when the cap bites, re-derive `alloc_gross = alloc_net + round(alloc_net·rate, 2)` so gross/net/tax stay rate-consistent — never clamp net independently of gross.

**4.2.2 `PS_ATCP_SHIPWRAP` → split shipping/wrapping by rate (DECIDED).** When `Configuration::get('PS_ATCP_SHIPWRAP')` is ON, PrestaShop taxes shipping AND wrapping at the **average cart product tax rate** (`getAverageProductsTaxRate()` = `gross_products/net_products − 1`, a net-weighted mean), not their own tax-rules-group. **We must NOT emit the blended average rate** — a non-canonical rate (e.g. 17.5%) has no Spanish IVA / e-invoicing tax category and is not acceptable downstream at invoice generation. Nor do we fail-fast and refuse ATCP shops.

Instead, **split the shipping (and wrapping) charge across the cart's canonical rate classes**, structurally identical to a cart-level discount spanning rate classes:
- Apportion the shipping **net** across classes by each class's product-net weight (the same basis the average is built from), so each sub-line carries a **canonical** rate.
- The split reconciles to PrestaShop's applied tax *by construction* in real arithmetic (the average IS the net-weighted mean: `Σ(ship_net·w_class·rate) = ship_net·avg`). Cents are reconciled per §4.2.3.
- Single-rate cart → one sub-line at the cart's rate (no blended rate ever appears). ATCP only produces multi-component shipping on genuinely mixed-rate carts.

**Reuses the discount multi-rate machinery** (`buildTwoCanonicalDiscountRateSegments` family) → PR2-area. **Verify** PrestaShop's `getAverageProductsTaxRate()` basis (`ONLY_PRODUCTS` net — confirm whether after-discount / ecotax-inclusive) matches our per-class weights, else the split total drifts from the applied shipping tax. Fixture: mixed-rate ATCP cart asserting `Σ(split tax) == round(ship_net · getAverageProductsTaxRate()/100, 2)` against PrestaShop's authoritative `ONLY_SHIPPING` tax.

**4.2.3 Cent-reconciliation primitive (general — DECIDED).** *Any* charge apportioned across N canonical rate classes (discounts, ecotax split, ATCP shipping/wrapping) must reconcile its rounded sub-lines to the **PrestaShop-authoritative total** — never reproduce PrestaShop's internal rounding (multi-stage `ps_round` at `getComputingPrecision()`=2dp, `PS_ROUND_TYPE`/`PS_PRICE_ROUND_MODE`-dependent; fragile to mirror). PrestaShop rounds the bucket once; we round per sub-line → `Σ round(xᵢ) ≠ round(Σ xᵢ)`, ±1c typical. Because the API requires exact order-level and per-line `gross == net + tax` reconciliation, this 1c must be absorbed. Define one primitive:
1. Apportion net across classes by weight; **largest-remainder** distribute residual cents until `Σ sub_net == PS_total_net`.
2. Per sub-line `tax = round(sub_net·class_rate, 2)`; **largest-remainder** distribute the tax residual until `Σ sub_tax == PS_total_tax`; set `gross = net + tax`.
The tax nudge (≤1–2c) stays inside the per-line `tax≈rate·net` tolerance while keeping `gross==net+tax` exact and the sums exact — so all three checks pass without a heavier exact-cent solver search. Anchor target = PrestaShop's authoritative `getOrderTotal(ONLY_X)` gross/net/tax. If no rate-consistent distribution can hit the target (rare, pathological weights), **fail loud** rather than ship a 1c-off payload. Reuse `allocateTwoAmountByWeights` (already largest-remainder) as the building block; discounts/ecotax/ATCP all call this one primitive.

### 4.3 Divergence handling — plugin-side, deterministic
A per-line check partially exists (`validateTwoLineItems`, 5556; throw at its call site, 2771) — but it **logs + returns bool** with a bare `tax÷net` tolerance check, a looser `NET_FORMULA_TOLERANCE` net check, and **no gross check**. So this is a **rewrite of the validator body, not a "repurpose"** — the merge gate must exercise the new branches. Rewrite it so the plugin gate matches the Two API's reconciliation semantics (else we false-decline carts the API accepts, or pass carts it rejects):
1. **Assert against the EMITTED 2dp amounts** (`net_amount`/`tax_amount`/`tax_rate` as sent), **not** PS-native pre-emit values — the API validates the emitted payload.
2. **Match the API's tolerance and rounding semantics** for the `tax ≈ rate·net` check (confirm against the API; current bare comparison is slightly tighter and can false-decline boundary residuals).
3. **Add the EXACT `gross == net + tax`** (2dp) per line — the API runs this in addition to `tax≈rate·net`; the validator has no gross leg today. (Product lines hold by construction; discount/free-ship/ATCP lines build the three legs via the §4.2.3 primitive, which keeps them consistent.)
4. **Tighten the net check (`NET_FORMULA_TOLERANCE`) to the API's line tolerance.** The looser value buys nothing — the API rejects the gap when enforced, and per the fail-loud principle a mismatch between emitted `qty·unit_price − discount` and the authoritative net is a divergence we want surfaced. Confirm the discount-absorption mechanism (3690+) keeps legitimate **high-quantity** lines within the tightened tolerance (high-qty fixture) before tightening; if a real class needs more headroom, that's an absorption gap to fix.

**Plugin aggregate note:** the plugin's *order-level* reconcile (`isTwoAmountWithinTolerance`, 2970) is tolerant, not exact. The exact guarantee we add is **per-line** (`gross==net+tax`, point 3).

On breach, **throw** (mirrors the negative-discount `throw` at 3704), making case 3 fail loud **regardless** of whether the API's strict reconciliation is enabled for the merchant. We do **not** silently switch to the amounts-rate or snap — that would be correcting the merchant's declaration. The API is the backstop.

**Blast radius (verified safe):** all three throw-reachable call sites — approval precheck (3095), live submit (`payment.php:168`), post-payment confirmation (`confirmation.php:125`) — wrap the builder in try/catch and degrade to a controlled decline (no white-screen / unhandled 500). The rich exception message goes to `PrestaShopLogger` (for the merchant); the buyer sees a generic "review your cart" notice. So the actionable detail is a merchant/logs artefact, not buyer-facing.

### 4.4 Rate precision (two-stage)
- **`TAX_RATE_PERCENT_PRECISION = 2`** (2dp-of-percent = 4dp-of-decimal) is the **operative ceiling** and is **correct** — it matches the Spanish e-invoicing limit. It preserves x.x5% rates (`round(8.55%, 2) = 8.55%` → 0.0855). Keep it.
- **`TAX_RATE_PRECISION`** (the `formatTwoTaxRate` precision) must be **≥ 4dp** so it does not re-truncate the normaliser's 4dp output (at the current 3dp, a faithful `0.0855` cannot survive a 3dp round-trip — `round(0.0855,3)=0.086`). Set it to **6dp**, matching native PrestaShop precision, as headroom above the ceiling. So 6dp is *necessary* and safe. (`formatTwoTaxRate` strips trailing zeros, so 0.21 emits `"0.21"`, not `"0.210000"` — fine; don't "fix" the strip.)
- `SNAPSHOT_TAX_RATE_PRECISION = 2` is intentionally independent (hash stability) and stays — the snapshot is computed from the emitted payload on both sides symmetrically, so refund/idempotency does **not** desync under 6dp.

### 4.5 Keep / Delete (sequenced — see §6)
**Keep** (the core of the prior work carries straight over — see §1's note on what it got right): product iteration + amounts; unit-price/discount precision (3690+, native 6dp, raises on negative discount); ecotax decomposition (`3411640`); shipping real-cost read + carrier-rate lookup (`0fa488b` — already the authoritative-source approach this design generalises); gift-wrapping-as-own-line (`fcbc793`); `buildTwoFallbackFreeShippingDiscountLine` (delete only its `tax÷net`+snap; rate mirrors shipping line); `buildTwoCanonicalDiscountRateSegments`/`solveTwoRateDiscountSplitInCents` (the declared-rate path for discounts — KEEP; delete only the `tax÷net` fallback arms 4147-4165/4476-4493, raise on unsolvable). Net: most of the existing structure stays; the change is making rate-sourcing uniform, not rebuilding the builder.

**Delete (sequenced):**
- The divide-then-snap logic at all five sites (product 3641-3687, shipping 3826-3842, wrapping 3885-3897, discount arms, Spanish canonical-rate fallback).
- `snapTwoTaxRateToKnownContexts` (5631) + `TAX_RATE_VARIANCE_TOLERANCE`/`TAX_RATE_CONTEXT_SNAP_TOLERANCE` (35-36): 6 callers — 3 in PR1 sites, 3 in PR2 discount arms (4153/4294/4482) → **helper + constants deleted in PR2** (last caller gone).
- `SPANISH_FALLBACK_TAX_RATE` (37) + consumers: `applyTwoSpanishCanonicalTaxRateFallbackToItems` (4978-5066, called at 3926 over the **full** items array incl. discount lines, guarded by `shouldApplyTwoSpanishTaxRatePolicy` at 3925), `shouldApplyTwoSpanishTaxRatePolicy`, `collectTwoKnownTaxRatesFromConfiguredProductRates` (4918), `collectTwoKnownTaxRatesFromPositiveItems` (5074). Because the fallback post-processes discount lines (PR2-owned), **PR1 forces `shouldApplyTwoSpanishTaxRatePolicy` false (the existing gate — do not add a new flag); PR2 deletes.**
- `normalizeTwoTaxRateToPercentPrecision` (5618) is **kept** (it enforces the precision ceiling) but must run on the declared rate (already canonical) — confirm it stays *after* sourcing, before format.

### Priority: replace the canonical-rate fallback early
`applyTwoSpanishCanonicalTaxRateFallbackToItems` resolves a line's rate to the nearest canonical value when the derived rate drifts. A side effect inherent to any snap-to-canonical step: in a mixed-rate edge case it can resolve a line to a neighbouring canonical rate (e.g. a 10% line landing on 21%) while still satisfying the amount checks, so that divergence isn't surfaced — which on an ES invoice would be an incorrect VAT breakdown. The declared-rate approach removes this class of edge entirely. Worth gating off early (PR1) so the new path takes over, with full removal in PR2.

## 5. Loud failure is the design, not a side effect

Stop deriving the rate from amounts → the declared rate no longer auto-matches the amounts → a genuine divergence (case 3) surfaces. The plugin's own assertion (§4.3) makes this **deterministic and independent of whether the API's strict reconciliation is enabled**; the API is the backstop. This is the rule from §2: *loud failure only where the declared rate ≠ the arithmetical reality.* A wrong-but-self-consistent declaration (case 2) passes silently and is the merchant's problem. The only failure that would be *ours* is a false rejection of a correctly-configured order caused by misreading the declared rate's address granularity (§4.1, §7).

**Bounding false positives:** the binding intent gate is the per-line tolerance, not a per-group exact check, so many-small-cheap-line carts do not aggregate into a reject. The one real per-line risk is `PS_ROUND_TYPE = ROUND_ITEM` + high quantity — covered by a §7 fixture. The wrapping `gross<net→gross=net` clamp (3950) silently masks divergence and must be **removed/fail-loud in PR1** (PR1 is what makes wrapping field-sourced).

## 6. Sequencing — two PRs

**PR1 — declared-rate relay, surgical, ships first:**
- Source product/shipping/wrapping rate from the declared rate via the shared address-correct helper (§4.1); fix the carrier helper (it reads delivery-first with invoice-fallback, but ignores `PS_TAX_ADDRESS_TYPE` — route it through the shared helper).
- Add the per-line divergence assertion + throw (§4.3).
- Set `TAX_RATE_PRECISION = 6`.
- Remove the wrapping `gross<net` clamp (or convert to fail-loud).
- Force `shouldApplyTwoSpanishTaxRatePolicy` false (gate off the canonical-rate fallback; do not delete — it touches discount lines).
- Delete the snap *calls* at the three PR1 sites only (helper survives for PR2).
- **Hard merge gate:** (a) a representative failing cart — **including a discount line** — captured and replayed green against the new builder; (b) a plumbing unit test (§7 item 1a). The staging Canary order (§7 item 1b) is the real zone-resolution proof but is **post-merge** config validation (depends on staging tax topology being set up), not a merge gate.
- Add a **high-quantity fixture** confirming the discount-absorption keeps net within the tightened tolerance (§4.3 pt 4); confirm a **single-rate discount does NOT enter the two-rate solver** (`solveTwoRateDiscountSplitInCents` returns null when low==high → would false-throw under "raise on unsolvable").
- Fix the free-ship `min()` cap (§4.2.1) — false-throws under the new assertion.
- **ATCP** (§4.2.2): check `PS_ATCP_SHIPWRAP`. If OFF (verify) PR1 needs no ATCP code beyond the check. If ON, the split-by-rate handling is **go-live-critical and couples to PR2's multi-rate solver** — pull that machinery forward. The flag check decides the timeline, not the design.
- Build the §4.2.3 cent-reconciliation primitive (or land it in PR2 with the discount rework if ATCP is off) and route discounts/ecotax through it.
- Fix the ecotax rate source: `extractTwoEcotaxLineBreakdown` (3990-3992) currently falls back to the country-only `$line_item['rate']`; route it through the shared helper with `PS_ECOTAX_TAX_RULES_GROUP_ID` (matches the rate PrestaShop embedded in `total_ecotax`).

**PR2 — discount attribution + removing the interim rate-derivation helpers (own ticket, golden fixtures):**
- Replace discount `tax÷net` arms with the exact-cent solver split; free-ship line mirrors the shipping line.
- Delete `snapTwoTaxRateToKnownContexts`, the tolerance constants, the Spanish canonical-rate fallback + `shouldApplyTwoSpanishTaxRatePolicy` + both `collectTwoKnownTaxRates*` helpers (all callers now gone).

## 7. Verifications & empirical tests (gate where noted)
1. **`$line_item['rate']` is country-only** (PS core, verified 1.6→8.x — §4.1). The *design* question is settled (always source via the address-correct helper); what remains is validating the merchant's actual config resolves the right sub-national rate. **(a) [PR1] a deterministic unit/fixture test** of `getTwoConfiguredTaxRateDecimalForGroup` — **scoped to what it can prove.** The repo's PHPUnit stub `TaxManagerFactory` (`tests/bootstrap.php`) **ignores the address** and returns a flat rate by group id, so the unit test verifies **plumbing only** (correct group resolved, address-correct `$cart` passed, decimal returned + normalised) — it **cannot** prove real country-vs-zone resolution. **(b) [post-merge] a staging Canary/Ceuta/Melilla order** against a live PrestaShop tax engine — the real zone-resolution proof. Only valid if staging replicates the merchant's tax topology — a state/zip-scoped `TaxRule` with an IGIC/0% rate inside the product's group; a flat-21% staging group passes falsely. **Owner-assigned task:** configure the staging IGIC/Ceuta/Melilla zones + a 0% and a deliberately-misconfigured line. Do **not** let (a) retire the granularity risk — (b) is the proof.
2. **Confirm whether the API's strict amount-reconciliation is enabled for the merchant** — the plugin-side throw (§4.3) makes us independent of it, but knowing the state tells us what the current rejections actually are.
3. **Capture a representative failing cart** (decline payload + PS cart dump), replay green/loud-correct against the new builder.
4. **PS rounding config** — read `PS_ROUND_TYPE` + `PS_PRICE_ROUND_MODE`; if `ROUND_ITEM`, add the high-qty + discount boundary fixture (§5).
5. **Wrapping group config** — audit `PS_GIFT_WRAPPING_TAX_RULES_GROUP` vs what PrestaShop applies to `ONLY_WRAPPING`.
6. **`reduction_tax == 0` semantics** (4782) — fixture-verify (PrestaShop: 1=tax-incl, 0=tax-excl).
7. **`PS_ATCP_SHIPWRAP` state for the target shop** — decides whether the §4.2.2 split-by-rate must ship in PR1 (ON → go-live-critical) or can land with PR2 (OFF). Also confirm the `getAverageProductsTaxRate()` basis matches our per-class weights (§4.2.2 fixture).

## 8. Propagation
Same lever for all Two plugins: **relay declared rates, compute amounts, never derive; fail loud on divergence.** Each plugin's declared-rate source differs (`WC_Tax`; Magento tax calculation; PrestaShop `TaxCalculator`). Shared reconciliation spec + golden fixtures (free-ship+discount, ecotax+free-ship, wrapped+discounted, mixed-rate, ROUND_ITEM+high-qty, sub-national zone).

**Caveats:**
- Exact for **single-rate** tax groups. For **compound groups** (US state+county, CA GST+PST, multi-`TaxRule` EU), the declared "rate" is a blend equalling no single canonical rate. PS-native answer: iterate `TaxCalculator->taxes` / `TaxRulesGroup::getTaxesRules` and **emit one component per constituent `Tax`** — do not pick a rate.
- `$line_item['rate']` (`Product::getTaxesInformations`) resolves at country level but ignores state/postcode; the cart amounts use the full tax-address. Discount attribution does not port as portable boilerplate.

## 9. CI compatibility matrix (PHP × PrestaShop), dynamically discovered

The Magento plugin already does this and is the reference pattern: a single-source-of-truth shell script (`dev/magento-support-matrix.sh`) discovers the supported **Magento × PHP** window from upstream at CI time, a `discover-…-matrix` job runs it and emits GitHub-Actions matrix JSON, and the lint / PHPStan / install-smoke jobs consume it via `fromJSON`. No hand-maintained EOL list or min-PHP map — the matrix tracks the platform's lifecycle automatically. **We adopt the same approach for PrestaShop here.** (WooCommerce should gain the equivalent — flagged in §11, not dictated here.)

Unlike §10 and §11, this is implementable directly from the Magento reference — build it alongside (or just after) PR1 so the rate-sourcing change is exercised across the live supported-version window from the start, rather than a single pinned version.

### 9.1 Version-support policy: current major − 2
Declare it explicitly: **we support the current PrestaShop major and the two preceding major lines.** Because PrestaShop renumbered (…1.6 → 1.7 → 8 → 9), the three lines in scope today are **9.x, 8.x, and 1.7.x**. When 10.x releases, the window shifts to 10/9/8 and 1.7.x drops out. The discovery script (§9.2) derives the floor from this policy against the live current major, so the window self-updates — no hand-maintained EOL date.

### 9.2 PrestaShop — `dev/prestashop-support-matrix.sh`

A script that emits the CI matrix, mirroring the Magento one's flags (`--emit-matrix` for the PrestaShop × PHP cross-product used by install-smoke/PHPStan; `--emit-php-lint-matrix` for the PHP-only lint job; no flag → human-readable report). Discovery strategy:

1. **PrestaShop versions** — query `https://api.github.com/repos/PrestaShop/PrestaShop/tags` (and/or `/releases`) for release tags. PrestaShop's scheme is mixed-arity (`1.7.8.x`, `8.x.y`, `9.x.y`); determine the current major, then per the §9.1 policy keep the latest patch of the current major and the two preceding major lines (today: `9.x`, `8.x`, `1.7.x`).
2. **PHP constraint per PrestaShop version** — fetch that tag's raw `composer.json` from GitHub and parse `require.php` (e.g. `>=8.1` → minors `[8.1, 8.2, …]`), exactly as the Magento script parses Magento's constraint.
3. **Currently-supported PHP minors** — query `https://www.php.net/releases/?json` (active + security) and **intersect** with each PrestaShop version's constraint. (Keeping `1.7.x` in the window pins us to its older PHP floor — the visible, deliberate cost of the minus-two policy, surfaced by the matrix rather than hidden.)
4. **`intentionally_excluded` escape hatch** — same two entry forms as Magento (whole-version exclusion, or `version:php=X.Y` combo exclusion), each documenting *why* (typically: no official Docker image published for that pairing yet) so a future maintainer knows when it can drop.
5. **Emit** the cross-product as GHA matrix JSON.

**Install-smoke harness:** use the official `prestashop/prestashop` Docker images (tagged by PrestaShop version × PHP) — install the module into the container and assert it installs/enables cleanly per matrix cell. Where an image isn't published for a discovered cell, that cell goes on `intentionally_excluded` with the reason.

**Implementation conventions (copy from the Magento script):** retry-once-on-transient with HTTP-status + JSON-shape validation before piping to `jq`; authorise the GitHub API with `GH_TOKEN`/`GITHUB_TOKEN` when present to dodge anon rate limits; fail the job (not silently empty) if discovery returns no versions.

### 9.3 Min-version guard (CI fails when our declared min drifts below policy)
The plugin declares its supported floor in `ps_versions_compliancy` (`twopayment.php`, currently `min = 1.7.6.0`). Add a CI check comparing this declared min against the policy floor (current major − 2) computed by §9.2's discovery: **fail the build if the declared min is older than current-major-minus-two.** Today `1.7.6.0` sits inside the `1.7.x` floor → passes. When 10.x ships, the floor becomes `8.x` and the declared `1.7.6.0` min goes red — deliberately — forcing a conscious call: raise the min to `8.0`, or consciously extend the policy. The guard's job is to *prompt the rethink*, not silently enforce.

## 10. End-to-end tests (requires its own design session)

This redesign is verified at the unit/fixture level plus the staging order in §7, but the plugin has no end-to-end coverage exercising both surfaces it touches:
- **Admin** — tax-rules-group configuration, ecotax / gift-wrapping / ATCP toggles, payment-term and module settings.
- **Checkout** — cart → order → across the permutations this design depends on (single-rate, mixed-rate, discounts, free-shipping, ecotax, wrapping, sub-national zones).

**Requirement (not spec'd here):** a dedicated design session to define the coverage — which admin and checkout journeys, which cart permutations, and the harness (unit/integration vs browser-driven; the repo already has a PHPUnit stub harness + `tests/`) — followed by implementation of that suite. The intent is that the rate-sourcing change lands on a real, repeatable safety net rather than ad-hoc fixtures. Flagged as a required follow-up; out of scope for this document.

## 11. Robustness review of Magento & WooCommerce (required next step)

The same class of behaviour this design addresses — authoritative-rate sourcing, ecotax (or equivalent), shipping under free-ship rules, wrapping, discount attribution across rate classes, sub-national zones — has direct analogues in the other Two plugins (Magento and WooCommerce). §8 states the shared principle; this section makes the **review itself a tracked requirement**. Concrete changes to those plugins are out of scope here (that would be scope creep) — this is a pointer to review them.

**Requirements (not spec'd here):**
- **Tax handling** — a focused per-platform review of Magento and WooCommerce tax sourcing against the same standard (relay the declared rate, never derive, fail loud on divergence, source from the authoritative engine: `WC_Tax`; Magento tax calculation) — confirm each is similarly robust or surface where it is not.
- **WooCommerce CI compatibility matrix** — WooCommerce should gain the same dynamically-discovered CI matrix as Magento and PrestaShop (§9). For WooCommerce that means a **third axis — WooCommerce × WordPress × PHP** — sourced from the WordPress.org plugin API (`requires_php` / `requires` / `tested`), the WP core stable-check API, and php.net. Flagged for its own design, not dictated here.
- **Min-version guard parity** — add the §9.3 min-vs-policy CI guard to Magento (and WooCommerce). We likely do **not** have this guard in Magento today — confirm, and add it so all three plugins fail CI when their declared minimum drifts below the agreed support policy.

Likely outcome: parity work mirroring this where gaps exist. Flagged for next-steps planning.
