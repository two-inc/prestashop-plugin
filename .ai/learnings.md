# Learnings & Bug Fixes

> **Self-updating file** - AI agents should append new learnings when fixing bugs.
> Add entries at the TOP of each section (newest first).

---

## How to Add Entries

```markdown
### [YYYY-MM-DD] Brief Title
**Problem**: What went wrong
**Root Cause**: Why it happened
**Fix**: How it was solved
**Files**: Which files were changed
```

---

## Shipping & Tax Calculation Issues

### [2026-01-22] Shipping Missing When Free Shipping Cart Rule Active
**Problem**: Order intent `gross_amount` was €29 less than checkout total; shipping line item not included
**Root Cause**: Code used `$cart->getOrderTotal(true, Cart::ONLY_SHIPPING)` which returns **0** when a "Free shipping" cart rule is active (known PrestaShop behavior)
**Fix**: Use `$cart->getPackageShippingCost($cart->id_carrier, true)` to get carrier's actual cost BEFORE free shipping rules are applied. This method returns the carrier's configured price regardless of cart rules.
**Files**: `twopayment.php` → `getTwoProductItems()` shipping detection

### [2026-01-22] Tax Rate Shows 20% Instead of 21% (Rounding Errors)
**Problem**: Tax rate displayed as 0.200000 instead of 0.210000; calculated from amounts instead of configured rate
**Root Cause**: Code was calculating tax rate from `(gross - net) / net` which can have rounding errors
**Fix**: Use PrestaShop's native `rate` field from cart products as primary source (it's the configured tax percentage). Only fall back to calculated rate when native field is unavailable or 0 but tax was actually charged.
**Files**: `twopayment.php` → `getTwoProductItems()` tax rate calculation

### [2026-01-22] Shipping Tax Rate Not Using Carrier Configuration
**Problem**: Shipping tax rate was derived from amounts, not from carrier's configured tax rules group
**Fix**: Use `$carrier->getIdTaxRulesGroup()` + `TaxManagerFactory::getManager()` + `getTaxCalculator()` to get the actual configured shipping tax rate
**Files**: `twopayment.php` → `getTwoProductItems()` shipping tax calculation

### [2026-01-22] Store Tax Rules Not Applied (0% Tax on Products)
**Problem**: Products showed 0% tax despite "ES Standard rate (21%)" tax rule being assigned
**Root Cause**: **Store misconfiguration** - Tax rule existed but wasn't configured to apply to Spain (ES) country. This is a PrestaShop admin configuration issue, NOT a module bug.
**Fix**: Store admin needed to edit the tax rule and add Spain to the country list
**Files**: N/A - Store configuration issue

---

## jQuery & JavaScript Issues

### [2026-08-05] Checkout "flicker" bugs are usually the surcharge sync's full page reload
**Problem**: Two rounds of fixes to the sole-trader chips and the payment tile did not stop either flickering.
**Root Cause**: `syncSurchargeCartLine()` -> `triggerNativeCartRefresh()` is a **full checkout-page reload** on the payment step (the step carries core's `js-cart-payment-step-refresh` marker), and it fires on every payment-option select *and* deselect. Two consequences, measured with a rAF-rate sampler against the staging shop rather than reasoned about: (1) anything the browser builds after a round trip is missing from the reloaded document until that round trip completes - the chips were absent at first paint and appeared ~280ms later, on every load; (2) the reloaded page paints every payment option's additional-information block EXPANDED, because a reload wipes radio state and core only collapses the unselected ones at DOM ready - so the Two tile was ~497px tall at first paint and 0px ~34ms later.
**Fix**: Put the answer in the markup (server-rendered chips + visibility, adopted by the JS) and suppress the first paint from the `<head>` for the reload that is on its way to a different payment method. Nothing running at DOM ready can fix a first-paint problem.
**Lesson**: Instrument before diagnosing. A 40ms sampler over `getBoundingClientRect()` + `sessionStorage` (so it survives the reload) answered in one run what two rounds of source reading got wrong.
**Third lesson (round-3 review)**: moving a fail-soft answer into the RENDER path is a latency decision, not just a correctness one. The tile now reads the answer cache-only and lets the browser's existing request resolve a cold cache off the render path - the same shape the rest of this module already uses for checkout-render reads. And an answer that can now arrive from two places (server markup, client fetch) needs an explicit ordering rule: adoption bumps a generation counter and the in-flight request drops its result if it moved, because "the request was in order when it was issued" says nothing about whether the server answered afterwards.
**Fourth lesson (process)**: mutation testing that edits tracked files WILL contaminate a commit. One stray `$types_cache` write survived a restore, got committed, and broke CI by making the first caller of a failed lookup see null and every caller after it see false. Verify `git diff` hunk by hunk after a mutation run, not just `git status`.
**Second lesson (adversarial review)**: moving a fail-soft answer into markup the browser adopts and never re-asks changes what "fail soft" costs. `isAvailable()` collapsing a timeout into "no" is fine for a capability gate and wrong for an adopted answer - it needs a third state. Same class of trap: an availability answer resolved from the shop's country rather than the cart's billing country was invisible on staging only because the two happened to match.
**Files**: `views/js/modules/TwoSoleTrader.js`, `views/js/modules/TwoPaymentStepFlashGuard.js`, `views/js/modules/TwoCheckoutManager.js`, `views/templates/hook/paymentinfo.tpl`, `twopayment.php`, `classes/TwoSoleTrader.php`, `views/css/two.css`

### [2025-10-06] jQuery Race Condition on PS 1.7.6.x
**Problem**: `$ is not defined` errors on checkout
**Root Cause**: Theme-dependent jQuery loading - scripts execute before jQuery loads
**Fix**: Triple-layer PHP fallback + `waitForJQuery()` JS wrapper
**Files**: `twopayment.php`, all JS modules

---

## API Integration Issues

### [2025-11-21] Tax Rate 0% Despite Tax Applied
**Problem**: Two API rejects with tax formula validation error
**Root Cause**: PrestaShop `rate` field can be 0 even when tax exists in gross/net
**Fix**: Calculate rate from `(gross - net) / net` as source of truth
**Files**: `twopayment.php` → `buildOrderPayload()`

### [2025-11-21] Phone Validation Failures
**Problem**: "Invalid phone number" from Two API
**Root Cause**: Customer used `phone_mobile` field, `phone` was empty
**Fix**: Fallback chain: `phone` → `phone_mobile`
**Files**: `twopayment.php` → buyer payload building

### [2025-11-14] Gross Amount Mismatch (1 cent off)
**Problem**: "Total invoice amount doesn't match" errors
**Root Cause**: PHP floating point vs PrestaShop's stored rounded values
**Fix**: Use `Tools::ps_round()` and 2-cent tolerance constant
**Files**: `twopayment.php` → line item calculations

---

## Checkout Flow Issues

### [2025-10-06] Order Intent Fires Multiple Times
**Problem**: API rate limiting, duplicate processing
**Root Cause**: `updatedPaymentForm` event fires on every checkout change
**Fix**: 800ms cooldown + result caching + `isProcessing` guard
**Files**: `TwoCheckoutManager.js`, `TwoOrderIntent.js`

### [2025-10-06] Payment Option Not Found
**Problem**: Two payment option not detected in custom themes
**Root Cause**: Themes customize payment markup differently
**Fix**: Multiple selector strategies (data-attr → value → action → text)
**Files**: `TwoCheckoutManager.js` → `findTwoPaymentOption()`

---

## Configuration Issues

### [2025-11-14] API Key Typo Migration
**Problem**: Old installations had `PS_TWO_MERACHANT_API_KEY` (typo)
**Root Cause**: Original code had typo in config key
**Fix**: Upgrade script migrates to `PS_TWO_MERCHANT_API_KEY`
**Files**: `upgrade/upgrade-2.2.0.php`

---

## Template for New Entries

```markdown
### [YYYY-MM-DD] Title
**Problem**: 
**Root Cause**: 
**Fix**: 
**Files**: 
```
