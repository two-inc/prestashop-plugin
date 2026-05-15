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
