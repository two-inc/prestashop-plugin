# Two Payment Plugin for PrestaShop

> **AI Agent Context File** - Primary source of truth for AI development assistance.
> This file should be read at the start of every session and updated when learnings occur.

---

## Self-Improvement Protocol

### When to Update This File

AI agents SHOULD update this file when:
- A bug is fixed that reveals a pattern worth documenting
- A new architectural decision is made
- An existing rule is found to be incorrect or incomplete
- A common mistake keeps recurring
- Cross-version compatibility issues are discovered

### How to Update

1. **Add learnings** to the appropriate section below
2. **Correct errors** directly - don't leave wrong information
3. **Add new patterns** with code examples
4. **Update version info** when module version changes
5. **Timestamp significant updates** in the Revision History section

### Update Format

When adding learnings, use this format:
```markdown
### [Category] Brief Title
**Problem**: What went wrong
**Solution**: How to fix it
**Code**: Example if applicable
```

---

## Module Overview

| Aspect | Value |
|--------|-------|
| Module | `twopayment` v2.3.2 |
| Platform | PrestaShop 1.7.6 - 9.x |
| Type | B2B Payment Gateway (Buy Now Pay Later) |
| Main File | `twopayment.php` (~3900 lines) |
| API | Two Payment API v1 |
| Grade | **Production Payment Module** |

### File Structure

```
twopayment/
├── twopayment.php              # Main module (hooks, config, API)
├── config.xml                  # Module metadata (version here too!)
├── CLAUDE.md                   # This file - AI context
├── controllers/front/
│   ├── orderintent.php         # AJAX: Order Intent validation
│   ├── payment.php             # Payment processing
│   ├── confirmation.php        # Order confirmation
│   └── cancel.php              # Order cancellation
├── views/js/modules/
│   ├── TwoCheckoutManager.js   # Checkout orchestration (1600+ lines)
│   ├── TwoOrderIntent.js       # Order Intent client-side
│   ├── TwoCompanySearch.js     # Company autocomplete
│   └── TwoFieldValidation.js   # Form validation
├── classes/
│   └── TwoInvoiceUploadService.php  # Invoice upload service
├── views/templates/hook/
│   └── paymentinfo.tpl         # Payment UI template
└── upgrade/                    # Version migration scripts
```

---

## Critical Rules

### 1. Payment Module Safety (NON-NEGOTIABLE)

```
⛔ NEVER deploy untested payment code to production
⛔ NEVER log sensitive data (API keys, tokens, full card numbers)
⛔ NEVER trust client-side validation alone
⛔ NEVER expose API keys to frontend JavaScript
✅ ALWAYS use try-catch around payment operations
✅ ALWAYS validate server-side before processing
✅ ALWAYS test on multiple PrestaShop versions
```

### 2. Cross-Version Compatibility

**PrestaShop Version Differences:**

| Version | jQuery | Asset Registration | Key Notes |
|---------|--------|-------------------|-----------|
| 1.7.6-1.7.8 | Theme-dependent, may not load | `registerJavascript()` | jQuery often missing! |
| 8.x | Core managed | `registerJavascript()` | Symfony-based |
| 9.x | Core managed | `registerJavascript()` | Latest Symfony |

**ALWAYS implement triple-layer jQuery fallback:**

```php
// In hookActionFrontControllerSetMedia()

// Layer 1: PrestaShop's native method
if (method_exists($this->context->controller, 'addJquery')) {
    $this->context->controller->addJquery();
}

// Layer 2: jQuery UI (includes jQuery)
if (method_exists($this->context->controller, 'addJqueryUI')) {
    $this->context->controller->addJqueryUI(['ui.core']);
}

// Layer 3: CDN fallback (GUARANTEED)
$this->context->controller->addJS('https://code.jquery.com/jquery-3.6.0.min.js', false);
```

**JavaScript-side safety wrapper (REQUIRED for all JS initialization):**

```javascript
function waitForJQuery(callback, maxAttempts = 50) {
    if (typeof jQuery !== 'undefined' && typeof $ !== 'undefined') {
        callback();
    } else if (maxAttempts > 0) {
        setTimeout(() => waitForJQuery(callback, maxAttempts - 1), 100);
    } else {
        console.error('TwoPayment: jQuery not available after timeout');
    }
}

// Wrap ALL initialization code
waitForJQuery(function() {
    $(document).ready(function() {
        // Your code here
    });
});
```

### 3. Asset Loading (CRITICAL)

**Only load on checkout pages - NEVER on all pages:**

```php
public function hookActionFrontControllerSetMedia()
{
    $controller_name = Tools::getValue('controller');
    $is_checkout_page = in_array($controller_name, ['order', 'orderopc']) || 
        (isset($this->context->controller->php_self) && 
         in_array($this->context->controller->php_self, ['order', 'order-opc']));

    $is_module_page = (isset($this->context->controller) && 
        $this->context->controller instanceof ModuleFrontController &&
        $this->context->controller->module->name === $this->name);

    if (!$is_checkout_page && !$is_module_page) {
        return; // Don't load assets - breaks cart/product pages!
    }
    
    // Register assets with explicit order (NO async!)
    $this->context->controller->registerJavascript(
        'two-module-name',
        'modules/twopayment/views/js/modules/File.js',
        ['priority' => 201, 'async' => false]  // async:false is critical!
    );
}
```

### 4. AJAX Controller Pattern

```php
class TwopaymentOrderintentModuleFrontController extends ModuleFrontController
{
    public $ajax = true; // CRITICAL: Must be set for AJAX
    
    // Method name MUST be ajaxProcess + action parameter value
    public function ajaxProcessCheckOrderIntent()
    {
        // Validate token
        if (Tools::getValue('token') !== Tools::getToken(false)) {
            $this->ajaxDie(json_encode(['error' => 'Invalid token']));
        }
        
        try {
            // Your logic here
            $this->ajaxDie(json_encode($response));
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: ' . $e->getMessage(), 3);
            $this->ajaxDie(json_encode(['error' => 'An error occurred']));
        }
    }
}
```

**URL Construction (use ? for first param, not &):**

```php
$url = $this->context->link->getModuleLink($this->name, 'orderintent');
$url .= '?ajax=1&action=checkOrderIntent&token=' . Tools::getToken(false);
// WRONG: $url .= '&ajax=1';  // Breaks if no existing params!
```

### 5. Theme & DOM Compatibility

**Use multiple selector strategies (themes vary widely):**

```javascript
findTwoPaymentOption() {
    // Strategy 1: Data attribute (most reliable)
    let option = document.querySelector('[data-module-name="twopayment"]');
    if (option) return option;
    
    // Strategy 2: Input value
    option = document.querySelector('input[value*="twopayment"]');
    if (option) return option;
    
    // Strategy 3: Form action
    const forms = document.querySelectorAll('form[action*="twopayment"]');
    if (forms.length > 0) return forms[0].closest('.payment-option');
    
    // Strategy 4: Content search (last resort)
    const labels = document.querySelectorAll('.payment-option label');
    for (let label of labels) {
        if (label.textContent.includes('Two')) {
            return label.closest('.payment-option');
        }
    }
    return null;
}
```

### 6. Hook Safety Pattern

```php
public function hookAnyHook($params)
{
    try {
        // Your hook logic here
    } catch (Exception $e) {
        PrestaShopLogger::addLog(
            'TwoPayment: Hook error - ' . $e->getMessage(),
            3,  // Error level
            null,
            'Module',
            $this->id
        );
        return; // Don't break the page!
    }
}
```

### 7. Order Intent Anti-Duplication

```javascript
triggerOrderIntent() {
    // Guard 1: Cooldown (800ms minimum between calls)
    const now = Date.now();
    if (now - this._lastIntentRunAt < 800) {
        return;
    }
    this._lastIntentRunAt = now;
    
    // Guard 2: Result caching
    if (this.orderIntent && this.orderIntent.lastResult) {
        this.handleOrderIntentResult(this.orderIntent.lastResult);
        return;
    }
    
    // Guard 3: Already processing
    if (this.orderIntent && this.orderIntent.isProcessing) {
        return;
    }
    
    // Execute
    this.orderIntent.checkOrderIntent().then(result => {
        this.handleOrderIntentResult(result);
    });
}
```

---

## Common Issues & Solutions

### Shipping Missing When Free Shipping Cart Rule Active

**Problem**: Order intent gross_amount is less than checkout total by exactly the shipping cost.

**Root Cause**: `$cart->getOrderTotal(true, Cart::ONLY_SHIPPING)` returns **0** when a "Free shipping" cart rule is active.

**Solution**: Use `getPackageShippingCost()` to get carrier cost BEFORE cart rules:

```php
// CORRECT: Gets actual carrier cost regardless of free shipping rules
$shipping_cost = $cart->getPackageShippingCost((int)$cart->id_carrier, true);

// WRONG: Returns 0 when free shipping cart rule is active
$shipping_cost = $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
```

### Tax Rate Best Practice

**Problem**: Tax rate calculated from amounts can have rounding errors (shows 20% instead of 21%).

**Solution**: Use PrestaShop's native `rate` field as primary source, fall back to calculation:

```php
// PRIMARY: Use PrestaShop's configured rate (it's the canonical source)
$rate_from_field = isset($line_item['rate']) ? (float)$line_item['rate'] : 0;
$tax_rate = $rate_from_field / 100; // Convert percentage to decimal

// FALLBACK: Calculate from amounts only when rate field is 0 but tax was charged
if ($rate_from_field == 0 && $gross_amount > $net_amount) {
    $tax_rate = ($gross_amount - $net_amount) / $net_amount;
}
```

### Shipping Tax Rate Best Practice

**Problem**: Shipping tax rate derived from amounts instead of carrier configuration.

**Solution**: Use carrier's tax rules group:

```php
$carrier_tax_rules_group_id = $carrier->getIdTaxRulesGroup();
if ($carrier_tax_rules_group_id > 0) {
    $tax_manager = TaxManagerFactory::getManager($delivery_address, $carrier_tax_rules_group_id);
    $tax_calculator = $tax_manager->getTaxCalculator();
    $shipping_tax_rate_percent = $tax_calculator->getTotalRate();
}
```

### Phone Validation Failures

**Problem**: "Invalid phone number" from Two API.

**Solution**: Fallback to mobile:

```php
$phone = $address->phone;
if (empty($phone)) {
    $phone = $address->phone_mobile;
}
```

### Gross Amount Mismatch

**Problem**: "Total invoice amount doesn't match" error.

**Solution**: Use PrestaShop's rounding:

```php
$gross_amount = Tools::ps_round($calculated_gross, 2);
// Tolerance: const GROSS_AMOUNT_TOLERANCE = 0.02;
```

### Two API Tax Formula Compliance (CRITICAL)

**Problem**: "Line item tax amount differs from tax rate * net amount" API error.

**Root Cause**: Two API strictly validates: `tax_amount == net_amount * tax_rate`. PrestaShop may calculate a tax_amount that doesn't exactly match this formula due to rounding at different stages.

**Solution**: CALCULATE tax_amount from the formula instead of using PrestaShop's value:

```php
// CORRECT: Calculate tax_amount to guarantee formula compliance
$tax_amount = round($net_amount * $tax_rate, 2);
$gross_amount = $net_amount + $tax_amount;

// WRONG: Use PrestaShop's pre-calculated tax_amount (may not match formula)
$tax_amount = $line_item['total_wt'] - $line_item['total']; // Don't do this!
```

**Key insight**: The tax_rate and net_amount define the tax_amount. Never take tax_amount from one source and tax_rate from another - they must be mathematically consistent.

### jQuery Not Defined

**Problem**: `$ is not defined` or `jQuery is not defined`.

**Solution**: Triple-layer fallback (see section 2) + waitForJQuery wrapper.

### Store Shows 0% Tax Despite Tax Rule Assigned

**Problem**: Products have 0% tax even though a tax rule (e.g., "ES Standard rate 21%") is assigned.

**Root Cause**: This is a **store configuration issue**, not a module bug. The tax rule exists but isn't configured to apply to the relevant country.

**Solution**: Store admin must edit the tax rule in **International > Taxes > Tax Rules** and ensure the country (e.g., Spain) is added with the correct rate.

---

## API Reference

### Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/v1/merchant/verify_api_key` | POST | Validate credentials |
| `/v1/order/intent` | POST | Pre-check buyer eligibility |
| `/v1/order` | POST | Create order |
| `/v1/order/{id}` | GET | Get order details |
| `/v1/order/{id}/fulfillments` | POST | Mark as shipped |
| `/v1/order/{id}/refund` | POST | Issue refund |
| `/companies/v2/company` | GET | Company search |

### Environments

| Environment | Base URL |
|-------------|----------|
| Sandbox | `https://sandbox.api.two.inc` |
| Production | `https://api.two.inc` |

### Order States

```
CREATED → CONFIRMED → FULFILLED → (REFUNDED)
              ↓
          CANCELLED
```

---

## Configuration Keys

| Key | Type | Description |
|-----|------|-------------|
| `PS_TWO_MERCHANT_SHORT_NAME` | string | Merchant identifier |
| `PS_TWO_MERCHANT_API_KEY` | string | API key (NEVER expose!) |
| `PS_TWO_ENVIRONMENT` | enum | `development` or `production` |
| `PS_TWO_ENABLE_ORDER_INTENT` | bool | Enable pre-purchase check |
| `PS_TWO_PAYMENT_TERM_TYPE` | enum | `STANDARD` or `EOM` |
| `PS_TWO_USE_OWN_INVOICES` | bool | Upload own invoices |

---

## Version Bump Checklist

When updating version:

1. `twopayment.php` line ~51: `$this->version = 'X.Y.Z';`
2. `config.xml`: `<version><![CDATA[X.Y.Z]]></version>`
3. Create `upgrade/upgrade-X.Y.Z.php` if schema changes
4. Update `CHANGELOG.md`
5. Update version in this file's overview table

---

## Testing Checklist

Before any release:

- [ ] PrestaShop 1.7.8 (oldest supported)
- [ ] PrestaShop 8.x (current stable)
- [ ] PrestaShop 9.x (latest)
- [ ] Classic theme + one custom theme
- [ ] Order Intent approval and rejection flows
- [ ] Order fulfillment triggers Two API
- [ ] Order refund triggers Two API
- [ ] No JavaScript console errors
- [ ] No PHP errors in logs
- [ ] Assets only load on checkout pages
- [ ] Mobile responsive checkout

---

## Debugging

### Enable Debug Mode

Module Config → Other Settings → Enable Debug Mode → Yes

### Log Location

```bash
tail -f /path/to/prestashop/var/logs/*.log | grep TwoPayment
```

### Log Levels

- 1 = Info
- 2 = Warning  
- 3 = Error
- 4 = Major/Critical

---

## Revision History

| Date | Change | By |
|------|--------|-----|
| 2026-01-22 | v2.3.2: Re-enabled invoice upload feature (disabled by default, merchants customize their own invoice templates) | AI |
| 2026-01-22 | v2.3.1: Tax formula compliance fix, Plugin Information admin tab, shipping/free shipping fix | AI |
| 2026-01-22 | Fixed shipping detection with free shipping cart rules; improved tax rate calculation to use native PrestaShop fields | AI |
| 2026-01-22 | Created comprehensive CLAUDE.md with self-improvement protocol | AI |
| 2025-11-21 | v2.3.0: EOM payment terms, debug mode | Dev |
| 2025-11-14 | v2.2.0: Invoice upload, SSL verification | Dev |
| 2025-10-06 | v2.1.2: jQuery triple-layer fallback | Dev |

---

## AI Instructions Summary

1. **Read this file first** at the start of any session
2. **Follow the patterns** documented here exactly
3. **Update this file** when you learn something new
4. **Test across versions** - never assume 1.7.x works like 9.x
5. **Wrap in try-catch** - never break checkout
6. **Log appropriately** - but never sensitive data
7. **Use defensive programming** - multiple fallbacks always
