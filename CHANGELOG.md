# Changelog

All notable changes to the Two Payment module for PrestaShop will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## Latest Release: v2.4.0

**Release Date:** 2026-02-25

**Highlights:**
- Cart snapshot guard to block local order creation if cart changes after Two order creation
- Idempotency key header on `/v1/order` creation to prevent duplicate provider orders on retries
- Added attempt metadata columns for snapshot hash and order-create idempotency key

**Upgrade:** Includes database migration creating/updating `twopayment_attempt`.

---

## [2.4.0] - 2026-02-25

### Added
- **Checkout Attempt Persistence**: New `twopayment_attempt` table tracks provider-first checkout attempts
  - Stores attempt token, cart/customer linkage, Two order metadata, and lifecycle status
  - Supports idempotent callback handling and safe retries
- **Cart Snapshot Consistency Check**: Callback finalization validates cart still matches original checkout payload hash
  - If cart drift is detected, local order creation is blocked
  - Provider order is cancelled (best effort) and customer is sent back to checkout
- **Order Create Idempotency Header**: `/v1/order` calls include `X-Idempotency-Key`
  - Key is derived from cart/customer/environment and normalized snapshot hash
  - Reduces duplicate provider orders when requests are retried
- **Attempt Metadata Columns**:
  - `cart_snapshot_hash`
  - `order_create_idempotency_key`

### Changed
- **Provider-First Checkout Flow**: Payment controller now creates Two orders before local PrestaShop orders
  - Eliminates local order creation/deletion cycle on provider rejection
  - Prevents rejected attempts from producing local order side effects
- **Unified Checkout Company Resolver**:
  - Payment controller now uses shared module fallback logic for company/org-number extraction
  - Applies country-aware cookie validation and multi-field org-number extraction consistently at checkout
- **merchant_order_id Alignment**: After callback-time local order creation, module performs best-effort Two order update to set `merchant_order_id` to the real PrestaShop `id_order`
- **Callback Orchestration**:
  - Confirmation controller now supports `attempt_token` callback flow and creates local order only after verified provider state
  - Cancel controller now supports `attempt_token` cancellation without creating local orders
  - Both controllers keep legacy `id_order` paths for backward compatibility
- **Tax Payload Accuracy Hardening**:
  - Tax rates are now serialized with dedicated high precision (no money-format truncation)
  - Product tax rate selection now prioritizes applied PrestaShop amounts when configured and applied rates diverge
  - Order-level `tax_rate` is derived from final net/tax totals
- **Provider Error Handling Hardening**:
  - `getTwoErrorMessage()` now treats HTTP `>= 400` as an error even when provider body is empty/non-JSON
  - Nested `data.error_message`/`data.message` responses are now parsed consistently
- **Session Company Country Safety**:
  - Legacy company cookies without `two_company_country` are now cleared when validating against a known address country
  - Prevents stale cross-country company/org-number reuse in mixed-country checkouts
- **Business Account Gate Resilience**:
  - When account-type mode is enabled but `account_type` is missing, module now falls back to company + org-number presence
  - Avoids false-negative payment option hiding on installations where custom address fields are not reliably persisted

### Technical
- Added upgrade script `upgrade-2.4.0.php`
- Module version bumped to `2.4.0`
- `twopayment_attempt` schema includes snapshot and idempotency metadata
- Added strict line-item formula validation gate before building intent/create/update payloads
- Added back-office media hook implementation for module/admin order styling consistency
- Fixed settings persistence path: `PS_TWO_DISABLE_SSL_VERIFY` now saves through "Other Settings" handler (where field is rendered)
- Added test harness and automated checks:
  - Offline deterministic test runner (`php tests/run.php`)
  - PHPUnit-compatible test suite scaffolding (`tests/OrderBuilderTest.php`, `phpunit.xml.dist`)
  - GitHub Actions workflow for push/PR test execution
  - CI syntax checks now include core module/controller files in addition to test files
  - Added coverage for HTTP-only provider failures, legacy session company country edge cases, shared checkout company resolver behavior, admin media hook routing, account-type fallback gating, and SSL setting persistence paths

---
## [2.3.2] - 2026-01-22

### Added
- **Invoice Upload Feature**: Re-enabled the invoice upload functionality
  - When enabled, PrestaShop-generated PDF invoices are uploaded to Two when orders are fulfilled
  - Merchants can customize their invoice templates to include Two's payment details
  - PrestaShop invoice templates can be modified in `/themes/[theme]/pdf/invoice.tpl` or `/pdf/invoice.tpl`
  - Feature remains disabled by default - must be coordinated with Two before enabling

### Changed
- **Invoice Upload Configuration**: Re-enabled `PS_TWO_USE_OWN_INVOICES` toggle in admin settings
  - Clear instructions explaining merchants must customize their invoice template
  - Example Smarty code provided for adding Two-specific content only to Two orders
  - Shows how to use `{if $order->module == 'twopayment'}` conditional
  - Warning to contact Two support before enabling

### Technical
- No database schema changes required
- No new hooks required
- Invoice upload uses existing Three-step process (request URL, upload to cloud storage, poll status)

---

## [2.3.1] - 2026-01-22

### Added
- **Plugin Information Tab**: New admin tab displaying plugin capabilities, limitations, and troubleshooting tips
  - Clear list of what the plugin can and cannot do
  - Important requirements for customers (company name, phone, etc.)
  - Common troubleshooting tips with solutions
  - Support contact information and version display

### Fixed
- **Tax Amount Calculation**: Fixed "Line item tax amount differs from tax rate * net amount" API errors
  - Tax amount now calculated using Two's required formula: `tax_amount = net_amount * tax_rate`
  - Ensures mathematical consistency between tax_rate, net_amount, and tax_amount
  - Resolves API rejection for orders with rounding discrepancies
- **Shipping Cost with Free Shipping**: Fixed shipping detection when free shipping cart rules are active
  - Now uses `getPackageShippingCost()` to get carrier cost before cart rules are applied
  - Shipping line item now includes correct amount even with free shipping promotions
- **Tax Rate Sourcing**: Improved tax rate determination using PrestaShop's native `rate` field
  - Primary source: PrestaShop's configured tax rate (canonical value)
  - Fallback: Calculate from amounts when rate field is unavailable
  - Edge case handling for tax-exempt customers and rate field inconsistencies

### Changed
- **Tax Calculation Logic**: Tax amounts are now calculated from rates instead of taken from PrestaShop
  - Guarantees Two API formula compliance: `tax_amount = net_amount * tax_rate`
  - Gross amount validation with configurable tolerance
  - Debug logging for rate variances when debug mode is enabled

### Technical
- No database schema changes required
- Backward compatible with all existing configurations
- PHP 7.1+ compatible

## [2.3.0] - 2025-11-21

### Added
- **End-of-Month (EOM) Payment Terms**: New payment term type for B2B invoicing
  - Supports EOM+30, EOM+45, and EOM+60 day terms
  - Payment calculated from end of current month at fulfillment, plus selected days
  - Example: Order fulfilled Jan 15 with EOM+30 = Payment due Feb 28 (end of Jan + 30 days)
- **Payment Term Type Configuration**: Radio button selection in admin (Standard vs EOM)
  - Dynamic UI: EOM mode shows only 30/45/60 day options
  - Standard mode shows all available terms (7/15/20/30/45/60/90 days)
  - Clear explanations with real-world examples for each type
- **API Integration**: `duration_days_calculated_from: "END_OF_MONTH"` field added to order payload for EOM terms
- **Database Schema**: Added `two_payment_term_type` column to store term type per order
- **Enhanced Buyer Display**: 
  - Standard terms: "Pay in 30 days" (multilingual)
  - EOM terms: "Pay in 30 days from end of month" (clear, localized)
  - Dynamic description text changes based on term type
- **Admin Order View**: Shows "End of Month + 30 days" with EOM badge for clarity
- **Upgrade Script**: `upgrade-2.3.0.php` with backward-compatible defaults
- **Debug Mode**: Admin toggle for detailed diagnostic logging (Other Settings → Enable Debug Mode)
  - Logs tax calculations, rate fields, and gross/net amounts per product
  - Only enable when requested by Two support for troubleshooting
- **Phone Number Fallback**: Automatic fallback from `phone` to `phone_mobile` field
  - Handles cases where customers only provide mobile number
  - Graceful handling when no phone provided (Two API validates)

### Changed
- **Checkout Display**: Smart label/unit hiding for EOM terms (no verbose "Pay in EOM+30 days")
- **Payment Terms Selector**: Term format changes based on type (tooltips explain EOM)
- **API Payload Builder**: New `buildTermsPayload()` method conditionally adds EOM field
- **Available Terms Logic**: `getAvailablePaymentTerms()` filters based on term type
- **Tax Rate Calculation**: Now validates tax rate from actual amounts (gross - net / net)
  - Handles edge case where PrestaShop `rate` field is 0 but tax is applied
  - Logs anomalies when rate field doesn't match calculated rate
  - Uses calculated rate as source of truth (what customer actually pays)
- **Company Messaging**: Clearer guidance when company data is missing
  - "Go back to your billing address and enter your company name in the Company field"
  - "Go back to your billing address and search for your company name. Select your company from the results"
  - Specific status codes: `no_company`, `incomplete_company` for better UX

### Fixed
- Invoice upload feature temporarily disabled (will be re-enabled after further testing)
- **Tax Rate 0% Issue**: Fixed edge case where tax rate was sent as 0 despite tax being applied
  - Now calculates rate from PrestaShop's actual gross/net amounts
- **Phone Validation Errors**: User-friendly messages for invalid phone numbers
  - "The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country."
- **API Validation Errors**: Comprehensive parsing of Two API validation errors
  - Phone, email, address, and company validation errors now show user-friendly messages
  - Generic fallback for unknown validation errors

### Technical
- **PHP 7.1+ Compatible**: No spread operators, arrow functions, or typed properties
- **Backward Compatible**: Existing merchants default to STANDARD type
- **Historical Orders**: Upgrade script marks existing orders as STANDARD
- **ES5 JavaScript**: Uses `function()` syntax instead of arrow functions in loops
- **Security**: Whitelist validation for term type (only STANDARD or EOM accepted)

### User Experience
- Clear admin explanations with fulfillment date examples
- Language-friendly checkout display (works in ES, EN, DE, FR, etc.)
- Tooltips on EOM term options
- EOM badge in admin order view with explanation
- Concise term display without verbose text

## [2.2.0] - 2025-11-14

### Added
- Invoice upload feature: Automatic upload of PrestaShop-generated invoices to Two's system for merchant's who want to distribute their own invoices
- Three-step upload process: Request signed URL, upload PDF to Google Cloud Storage, poll upload status
- Database schema changes: Added columns for invoice upload tracking (`two_invoice_id`, `two_invoice_upload_status`, etc.)
- Configuration option: `PS_TWO_USE_OWN_INVOICES` to enable/disable invoice upload feature
- Order status hook: `hookActionOrderStatusUpdate` triggers invoice uploads on order fulfillment
- SSL verification: Enabled by default with configurable override for corporate networks
- Constants extraction: Extracted magic numbers to named constants for better maintainability
- Comprehensive README: Added detailed documentation covering features, installation, configuration, troubleshooting
- CHANGELOG.md: Version history tracking
- **Helper functions**: Added `getOrderStatusNames()` and `buildFulfillmentStatusDescription()` for better admin UX

### Changed
- API key configuration: Fixed typo (`PS_TWO_MERACHANT_API_KEY` → `PS_TWO_MERCHANT_API_KEY`)
- Order payload building: Improved accuracy to match PrestaShop invoices exactly
- Product names: Now include attributes (e.g., "Shirt (Size: S - Color: White)") to match PrestaShop invoices
- Tax calculations: Enhanced precision handling for exact invoice matching
- Code quality: Extracted magic numbers to constants (timeouts, HTTP status codes, payment terms, etc.)
- SQL queries: Improved security using PrestaShop's `Db::delete()` method
- Error handling: Enhanced logging and user-friendly error messages
- **Payment method subtitle**: Updated default subtitle from "Receive the invoice via EHF and PDF" to "Buy now, pay later - instant credit" for better customer appeal
- **Fulfillment setting label**: Improved clarity of "Finalize purchase when order is shipped" setting
  - New label: "Automatically fulfill orders with Two"
  - Enhanced description explaining automatic fulfillment, payment terms activation, and manual alternative
- **Order Status Mapping UI**: Added visual feedback showing currently active fulfillment trigger statuses
  - Form field description now displays active statuses in green
  - Confirmation message after saving shows list of active statuses
  - Helps merchants understand which statuses will trigger fulfillment

### Fixed
- SQL injection prevention: Improved validation and casting for order IDs
- Order Intent expiry: Fixed server-side validation timing
- Invoice matching: Resolved 1-cent discrepancies between PrestaShop and Two orders
- Formula validation: Fixed "Line item net amount" and "total invoice amount" errors
- Gross amount calculations: Ensured exact matching with PrestaShop invoices
- Tax subtotals: Fixed sum validation to match order totals exactly

### Security
- SSL certificate verification enabled by default
- SQL injection prevention improvements
- Input validation enhancements
- Server-side Order Intent verification

## [2.1.2] - 2025-10-06

### Added
- Order Intent blocking: Client-side and server-side validation
- Payment terms UI: Configurable payment terms selector
- Company search: Real-time company search with Two's Company API v2
- Order Intent check: Frontend validation before payment confirmation

### Changed
- jQuery loading: Multi-layer fallback strategy for PrestaShop 1.7.6.5 compatibility
- Checkout flow: Enhanced payment option detection and event handling

### Fixed
- jQuery compatibility issues with older PrestaShop versions
- Payment option detection across different themes
- Order Intent timing and validation

## [2.1.0] - 2025-09-26

### Added
- Initial release of Two Payment module for PrestaShop
- Basic payment integration
- Order creation and management
- Admin order information display

### Changed
- N/A (initial release)

### Fixed
- N/A (initial release)

---

## Version Format

- **Major** (X.0.0): Breaking changes or major feature additions
- **Minor** (0.X.0): New features, backwards compatible
- **Patch** (0.0.X): Bug fixes, backwards compatible

## Upgrade Notes

### Upgrading to 2.3.0

1. **Backup**: Always backup your database before upgrading
2. **Automatic Migration**: Upgrade script automatically:
   - Adds `two_payment_term_type` column (VARCHAR(20), default 'STANDARD')
   - Sets `PS_TWO_PAYMENT_TERM_TYPE` configuration to 'STANDARD'
   - Updates existing orders to STANDARD type (no visible change)
3. **Backward Compatible**: Existing merchants see no changes
   - Payment terms continue to work exactly as before
   - All existing orders display correctly as standard terms
4. **New Feature**: EOM payment terms available as opt-in
   - Configure in module admin: Payment Term Type radio button
   - Only affects new orders after enabling EOM
5. **API Compatibility**: EOM requires Two backend support
   - Test on staging environment first
   - Verify `duration_days_calculated_from` field is accepted
6. **No Breaking Changes**: Standard terms unchanged, EOM is additive

### Upgrading to 2.2.0

1. **Backup**: Always backup your database before upgrading
2. **API Key Migration**: The upgrade script automatically migrates `PS_TWO_MERACHANT_API_KEY` to `PS_TWO_MERCHANT_API_KEY`
3. **Database Schema**: Upgrade script adds new columns for invoice upload tracking
4. **Configuration**: New `PS_TWO_USE_OWN_INVOICES` option added (default: disabled)
5. **SSL Verification**: SSL verification now enabled by default (configurable in module settings)
6. **Payment method subtitle**: Default subtitle updated to "Buy now, pay later - instant credit"
   - Existing installations will keep their current subtitle unless changed in admin
   - New installations will use the new default
7. **Fulfillment setting**: Label and description improved for clarity
   - Functionality unchanged, only UI text updated
   - New label: "Automatically fulfill orders with Two"
8. **Order Status Mapping**: Now shows active fulfillment statuses visually
   - Form field description displays active statuses in green
   - Confirmation message shows active statuses after saving

### Breaking Changes

None in version 2.2.0 - all changes are backwards compatible.

### Deprecations

- `PS_TWO_MERACHANT_API_KEY` (typo) - automatically migrated to `PS_TWO_MERCHANT_API_KEY`

---


For detailed technical changes, see git commit history.
