# Changelog

All notable changes to the Two Payment module for PrestaShop will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Buyer surcharge shown as a real PrestaShop cart line** (TWO-24739 parity)
  - Selecting Two at checkout now adds the payment-terms fee as a hidden virtual product line, so the fee appears in PrestaShop's own order summary, cart, order and invoice totals - previously it existed only on the Two-side invoice
  - The line's net amount comes from the same live fee quote as the Two order payload (single computation path); its tax applies the admin-configured Surcharge Tax Rate through a module-managed tax rules group
  - Selecting any other payment method removes the line; add/remove is idempotent against re-clicks, reloads and resumed carts (front-controller stale-line guard)
  - Order creation self-heals the line server-side and fails closed if the cart's fee and the Two payload's fee ever diverge beyond rounding tolerance
- **Graceful invoice retrieval when order is not yet fulfilled** (TWO-25042, part of TWO-25040)
  - Invoice PDF downloads (customer order page and admin order view) are now routed through module controllers instead of linking the browser directly to the payment API
  - On `400 ORDER_NOT_FULFILLED` the module checks the order state: `FULFILLING` shows an informational "not ready yet" notice, `FULFILLED` retries the fetch once, and any other state is reported with the state named
  - Customer downloads are protected by the same secure-key ownership guard as the cancel/confirmation callbacks (guest checkout included); admin downloads go through a permission-gated admin controller

## Latest Release: v2.4.0

**Release Date:** 2026-02-25

**Highlights:**
- Cart snapshot guard to block local order creation if cart changes after Two order creation
- Idempotency key header on `/v1/order` creation to prevent duplicate provider orders on retries
- Added attempt metadata columns for snapshot hash and order-create idempotency key

**Upgrade:** Includes database migration creating/updating `twopayment_attempt`.

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
- **Security hardening for callback and template surfaces**:
  - Legacy `id_order` confirmation/cancel front-controller paths now require secure callback authorization (query `key` or matching logged-in customer secure key) before any order-state mutation.
  - Buyer/admin/payment-return templates now escape dynamic Two/order fields before rendering links and text.
  - Production environment now enforces TLS verification even if the optional SSL-disable flag is set.
- **Order intent and callback hardening (provider-first parity)**:
  - Authoritative payment-submit order intent now fail-closes on strict reconciliation drift before provider `/v1/order_intent` call.
  - Callback-time local order creation now wraps `validateOrder()` with race-safe recovery using existing order-by-cart lookup.
  - Provider lifecycle cleanup now performs best-effort cancel on terminal post-create failures (including missing `payment_url`) with explicit lifecycle logs.
- **Order intent i18n normalization**:
  - Replaced remaining hardcoded order-intent user-facing errors with translation-surface strings.
  - Added Spanish (`es`) translations for the normalized order-intent error keys.
- **Coverage and validation documentation updates**:
  - Added test coverage for strict payment-submit drift blocking, callback race recovery, and provider cancel helper behavior.
  - Added real-engine integration matrix requirements for PrestaShop `1.7.8`, `8.x`, and `9.x` under `tests/integration/README.md`.
- **Order intent/company-search client auth safety + intent address parity**:
  - Added client-side request guards on frontend public Two API calls (`/v1/order_intent`, company search/detail endpoints) to block accidental auth header propagation.
  - Order intent payload now includes both `billing_address` and `shipping_address` for parity with order create/update payload composition.
- **Discount tax-rate canonicalization hardening**:
  - Discount-line fallback tax-rate derivation now snaps near-context drift to canonical cart tax contexts (for example `0.212` -> `0.21`), preventing provider-side strict VAT rate rejections on ES orders.
- **Shipping tax-rate canonicalization hardening**:
  - Shipping-line tax rate now snaps to canonical cart/carrier tax contexts when drift is only rounding noise (for example `0.211` -> `0.21`), preventing provider-side strict ES VAT rejections.
- **Additional tax-rate drift hardening (wrapping + product fallback)**:
  - Gift-wrapping tax-rate derivation now snaps to canonical cart tax contexts when drift is rounding-only.
  - Product-line fallback tax-rate derivation now reuses configured product tax-rate contexts to avoid minor synthetic drift when a line is missing/loses its direct rate field.
- **ES strict fallback default for unresolved line rates**:
  - Added an ES-only canonical normalization pass across built line items.
  - When a line tax-rate remains unresolved but formula-safe with canonical fallback, the fallback defaults to `0.21`.
- **Buyer confirmation payment-term clarity**:
  - Post-order buyer success card now renders invoice terms with explicit term type: `Standard + X days` or `End of Month + X days`.
- **Tax precision hardening for payload formulas**:
  - Line-item `tax_rate` serialization now preserves non-integer VAT rates (for example `0.055` for 5.5%) to keep `tax_amount = net_amount * tax_rate` consistent.
  - Tax subtotal grouping precision remains compatibility-safe while checkout snapshot tax-rate normalization remains stable at two decimals.
- **Cart-rule-aligned discount attribution**:
  - Discount line generation now prefers PrestaShop cart-rule monetary fields (`value_real`, `value_tax_exc`) to keep per-rule discount lines aligned with invoice semantics.
  - Weighted tax-context allocation remains as fallback when rule-level monetary metadata is unavailable.
  - Mixed cart-rule metadata handling now preserves complete rule rows and falls back only for unresolved remainder, with unresolved free-shipping remainder carved out on shipping VAT context.
- **Currency compatibility guardrails**:
  - Added explicit cart-currency compatibility checks in `hookPaymentOptions()` following PrestaShop payment-module patterns.
  - Added server-side currency guard in payment submit controller to fail fast before provider calls when currency is unsupported.
  - Added explicit ISO allowlist coverage in module checks for `NOK`, `GBP`, `SEK`, `USD`, `DKK`, and `EUR` (all fully supported).
- **Checkout address-basis consistency**:
  - Order intent backend now prioritizes invoice/billing address identity and keeps delivery only as fallback for backward compatibility.
  - Frontend order intent payload now sends both invoice and delivery address identifiers to keep mixed-theme flows compatible.
- **Idempotency and callback safety**:
  - Order-create idempotency key no longer depends on a time bucket for identical cart snapshots.
  - Added callback-time rebinding guard to prevent overwriting an existing local order binding with a different Two order ID.
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
- **Two cancellation/verification consistency hardening**:
  - Buyer portal URL resolution now uses explicit buyer domains by environment (`buyer.two.inc` for production and `buyer.sandbox.two.inc` for non-production), with a safe sandbox fallback for unknown environments.
  - Checkout callback handling now treats canceled attempts as terminal during confirmation, and cancel flow resolves local order linkage via cart fallback to avoid race-driven state mismatches between Two (`CANCELLED`) and PrestaShop.
  - Local order-state sync now force-maps provider `CANCELLED` to the configured PrestaShop cancellation status during confirmation handling and admin provider-sync refresh.
  - Legacy cancel callback no longer sets local cancelled state unless provider order fetch confirms `CANCELLED`, preventing transient local cancel entries when provider cancellation did not complete.
  - Legacy cancel callback now fail-closes when stored `two_order_id` mapping is missing, preventing local cancellation without a verifiable provider order link.
  - Fulfillment status updates now block/revert when the provider order is `CANCELLED` (using stored and fresh provider state checks), with explicit logs to prevent shipping progression on non-fulfillable Two orders.
  - Back-office fulfillment blocking now also surfaces an on-screen warning in the admin controller when a cancelled Two order is reverted to cancelled status.
  - Added `actionObjectOrderHistoryAddBefore` guard to rewrite pending `Verified` and fulfillment-trigger history inserts to the configured cancelled status when the provider order or attempt is terminally `CANCELLED`, preventing visible status flip-flops in order history.
  - Late confirmation race handling now blocks post-cancel status rewrites (`CONFIRMED`/`FAILED`) so a buyer-backed-out checkout remains cancelled.
- **Tax Payload Accuracy Hardening**:
  - Tax rates are now serialized to fixed 2 decimal places (`tax_rate` like `0.21`) across line items, tax subtotals, and checkout snapshots
  - Product tax rate selection now prioritizes applied PrestaShop amounts when configured and applied rates diverge
  - Top-level `tax_rate` is omitted from `/v1/order` and `/v1/order_intent` request payloads
  - `tax_subtotals` is optional and omitted entirely when `PS_TWO_ENABLE_TAX_SUBTOTALS` is disabled
  - Added back-office setting `PS_TWO_ENABLE_TAX_SUBTOTALS` in "Other Settings" to control whether `tax_subtotals` is sent
- **Provider Error Handling Hardening**:
  - `getTwoErrorMessage()` now treats HTTP `>= 400` as an error even when provider body is empty/non-JSON
  - Nested `data.error_message`/`data.message` responses are now parsed consistently
- **Session Company Country Safety**:
  - Legacy company cookies without `two_company_country` are now cleared when validating against a known address country
  - Prevents stale cross-country company/org-number reuse in mixed-country checkouts
- **Business Account Gate Strictness**:
  - When account-type mode is enabled, checkout now requires explicit `account_type=business` for Two visibility and order-intent approval.
  - Missing `account_type` no longer auto-falls back to company/org-number inference in strict mode.
- **Order Intent Enforcement**:
  - Removed the admin toggle for order intent pre-approval from "Other Settings"
  - Enforced order intent as mandatory for Two checkout server-side validation
  - Updated checkout initialization to always run order intent pre-check logic
- **Checkout Compatibility Hardening**:
  - Reworked `CustomerAddressFormatter` override to delegate to core formatter and apply only minimal Two-specific field adjustments
  - Removed remote CDN jQuery fallback from front-controller media hook
  - Added same-origin runtime jQuery fallback loader in frontend module bootstrap for legacy environments
- **Address Switching Reliability**:
  - Prevented stale same-country session company reuse when the shopper switches to a different checkout address/company
  - Added address-aware session marker (`two_company_address_id`) for company-cookie synchronization
  - Reset order-intent UI/server state and re-enable Two payment option after checkout address updates
  - Cleared stale hidden `companyid` values when company input changes to avoid cross-address mismatch blocking
- **Checkout Step Stability**:
  - Restricted order-intent submit interception to payment confirmation forms/buttons only (no blocking on personal-info or address step continue actions)
  - Removed fallback Two-selection detection based on generic form action matching to avoid false positives outside payment step
- **Organization Number Parsing**:
  - VAT extraction now strips prefix only when it matches the current address country ISO (prevents truncating valid org numbers like `SC806781` for GB)
- **Order Intent Company Context**:
  - Bound checkout approval message company name to backend order-intent payload company data
  - Cleared stale `lastCompany` state on order-intent reset to prevent cross-address message leakage
- **Address Selector Accuracy**:
  - Order-intent and company-cookie flows now read the selected (`:checked`) checkout address ID instead of the first address input in DOM
  - Order-intent server resolver now uses selected delivery/invoice address context consistently for country/company resolution
- **Two Payload Parity Hardening (Phase 1)**:
  - Intent/create/update payloads now share one server-side line-item builder and bottom-up amount derivation
  - Shipping is represented as explicit `SHIPPING_FEE` line and cart discounts as explicit negative line items
  - Added fail-closed order/cart reconciliation gate before outbound order payloads when totals drift beyond tolerance
- **Order Intent Auth Boundary**:
  - Added endpoint-aware header policy so `/v1/order_intent` never includes `X-API-Key`
  - Server-to-server Two endpoints keep API-key authentication on backend requests
- **Payment Submit Authorization Hardening**:
  - `/payment` now performs a fresh backend `/v1/order_intent` check and treats frontend intent cookies as telemetry only
  - Checkout submit token validation is enforced before provider calls in payment submission
- **Callback Amount Integrity**:
  - Callback-time `validateOrder()` now uses provider `gross_amount` from Two order response
  - Local order creation is blocked when provider amount is missing/invalid

### Fixed
- **Gift wrapping parity**:
  - Added explicit gift wrapping line-item construction so wrapping totals are represented in Two payloads and reconcile with PrestaShop grand totals.
- **Order intent payload regression on rounded mixed discounts**:
  - Discount line-item tax rate now uses higher precision when derived from rounded net/tax splits to preserve `tax_amount = net_amount * tax_rate` validation in large cart-rule discount scenarios (including free-shipping combinations).
- **Cart-rule discount VAT context compliance**:
  - Cart-rule discount rows now split into canonical tax-rate segments when needed, avoiding blended synthetic VAT rates while preserving per-rule net/gross totals.
  - Improves provider compatibility on strict VAT validation paths for mixed discount baskets.
- **Fallback free-shipping attribution hardening**:
  - When cart-rule monetary metadata is incomplete, fallback discount logic now attributes free-shipping discounts to the shipping VAT context first.
  - Reduces blended shipping/product discount attribution drift on mixed-tax baskets in fallback mode.
- **Order intent account-type strict enforcement**:
  - In account-type mode, order intent now blocks missing/non-business account types instead of treating missing values as business.
- **Ecotax explicit line modeling**:
  - Product lines now split ecotax into a dedicated `SERVICE` line when safe ecotax totals are present, preserving formula integrity and explicit tax context.
- **Payment term cookie warnings in tests/runtime**:
  - Guarded cookie reads in `getSelectedPaymentTerm()` to avoid undefined property warnings.
- **Buyer metadata warning suppression**:
  - `buyer_department` and `buyer_project` payload fields are now read with property checks to avoid undefined property warnings on default address entities.
- **Checkout Address Formatter Stability**:
  - Fixed `CustomerAddressFormatter` override constructor to call `parent::__construct(...)`
  - Prevents `Call to a member function trans() on null` fatals on `/order` during checkout address step rendering
  - Preserves Two-specific field adjustments while keeping core formatter translator initialization intact
- **Checkout Address Field Order**:
  - Restored country selector positioning immediately before company field in checkout addresses
  - Keeps core field metadata/validation intact by reordering existing formatter output instead of rebuilding fields
- **Address Identification Number Guard**:
  - Added frontend guard on checkout address submit to prevent backend failures when country requires identification number and `dni` is empty
  - Auto-fills `dni` from `companyid`/`vat_number` when available before submit
- **Checkout Country Switch (UK → ES) 500 Regression**:
  - Fixed `CustomerAddressFormatter` override `setCountry()/getCountry()` to delegate to core formatter state
  - Prevents stale country format when shopper switches address country during checkout (e.g. UK to Spain)
  - Ensures ES-required `dni` validation is applied before persistence, avoiding `Property Address->dni is empty` fatals
- **Order Intent Reconciliation False Negatives**:
  - Increased order/cart reconciliation tolerance to `0.02` to match real PrestaShop cent-level rounding drift
  - Reconciliation drift is now warning-level by default and does not block order-intent precheck payloads
  - Reconciliation threshold checks now compare integer cents to avoid float precision boundary rejects at exactly `0.02`
- **Provider-First Reconciliation Handling**:
  - Intent payload builder continues when cart reconciliation drift is detected
  - Create/update payload builders only hard-block on material mismatches (> `1.00`) to guard true parity errors
  - Module logs drift details for observability while avoiding local false-negative blocks from cent-level artifacts
- **Presta-Native Amount Modeling**:
  - Product and shipping line monetary fields now keep PrestaShop net/tax/gross totals as canonical values
  - Discount totals are split across detected tax contexts instead of a single blended synthetic discount line
  - Preserves line-level formula compliance while better matching PrestaShop rounding behavior
- **Discount Rule Description Warning**:
  - Guarded optional `value` key access in cart-rule description builder to avoid PHP warnings on stores where cart-rule payload omits that key

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
