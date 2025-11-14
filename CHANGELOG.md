# Changelog

All notable changes to the Two Payment module for PrestaShop will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

