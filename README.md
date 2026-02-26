# Two for PrestaShop — B2B Buy Now, Pay Later

## Overview

Two is a B2B payment method that lets your business customers pay by invoice with instant credit decisioning. This module integrates Two with PrestaShop 1.7.6+ following PrestaShop best practices and security standards.

## Features

### Core Functionality
- **Two Payment Option**: Visible for business accounts at checkout
- **Company Search**: Real-time company search and selection against Two's Company API v2
- **Organization Number Capture**: Hidden field (`companyid`) automatically populated from company selection
- **Order Intent Check**: Frontend validation before payment confirmation
- **Server-Side Verification**: Defense-in-depth security with server-side Order Intent verification
- **Payment Terms UI**: Configurable payment terms with user selection
  - **Standard Terms**: 7/15/20/30/45/60/90 days from fulfillment date
  - **End-of-Month (EOM) Terms**: 30/45/60 days from end of current month at fulfillment
- **Admin Integration**: Two order ID, state, status, and invoice URL displayed in order pages
- **Invoice Upload**: Automatic upload of PrestaShop-generated invoices to Two (optional feature)

### Security Features
- SSL certificate verification enabled by default
- Server-side and client-side Order Intent validation
- Rate limiting on Order Intent API calls
- Secure API key storage and validation

### Technical Features
- Cross-version compatibility (PrestaShop 1.7.6 - 9.x)
- Theme-agnostic implementation
- jQuery compatibility handling for older PrestaShop versions
- Comprehensive error logging with optional debug mode
- Order payload validation ensuring exact PrestaShop invoice matching
- Robust tax rate calculation with fallback validation
- User-friendly error messages for API validation failures
- Phone number fallback (phone → phone_mobile)
- Provider-first checkout finalization (local order created after provider verification)
- Cart snapshot validation before callback-time local order creation
- Idempotency key header on provider order creation requests

## Requirements

- **PrestaShop**: 1.7.6+ (tested up to 9.x)
- **PHP**: 7.2+
- **Theme**: Classic theme or 1.7-compatible themes
- **B2B Support**: Store must support B2B (business account type at address step)
- **SSL**: HTTPS required for production

## Installation

1. Download the module package
2. Go to PrestaShop Admin → Modules → Module Manager
3. Click "Upload a module" and select the module ZIP file
4. Click "Install" after upload completes
5. Configure the module (see Configuration section)

## Configuration

### Back Office Setup

1. **Install and Enable**: Install the module from Module Manager
2. **Environment Selection**: Choose Sandbox (testing) or Production environment
3. **API Key**: Enter your Two API key for the selected environment
   - The module validates the API key on save
   - Invalid keys will show an error message
4. **Payment Terms**: Configure payment term type and available terms
   - **Term Type**: Choose Standard or End-of-Month (EOM) terms
     - **Standard**: Payment due X days from fulfillment date (all durations available)
     - **EOM**: Payment due at end of current month + X days (30/45/60 only)
   - Enable/disable individual terms based on selected type
   - Set default payment term (defaults to 30 days if available)
5. **Optional Features**:
   - Enable/disable company name field requirement
   - Enable/disable organization number field requirement
   - Enable/disable department field
   - Enable/disable project field
   - Enable/disable Order Intent check (Required for use)
   - Enable/disable account type selection
   - Enable automatic invoice upload to Two
   - Configure SSL verification (default: enabled)

### Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| Environment | Sandbox or Production | Sandbox |
| API Key | Two merchant API key | Required |
| Payment Term Type | Standard or End-of-Month (EOM) | Standard |
| Payment Terms | Available terms based on type | 30 days enabled |
| Default Payment Term | Default term when multiple available | 30 days |
| Company Name | Require company name field | Enabled |
| Organization Number | Require organization number | Enabled |
| Department Field | Show department field | Disabled |
| Project Field | Show project field | Disabled |
| Order Intent | Enable Order Intent check | Enabled |
| Account Type | Show account type selector | Enabled |
| Auto Fulfill Orders | Automatically fulfill orders with Two when status changes | Enabled |
| Invoice Upload | Auto-upload invoices to Two | Disabled |
| SSL Verification | Verify SSL certificates | Enabled |
| Debug Mode | Enable detailed diagnostic logging | Disabled |

## Payment Terms: Standard vs End-of-Month (EOM)

The module supports two types of payment terms to match your B2B invoicing practices:

### Standard Payment Terms

Payment is due **X days from the fulfillment date**.

**Example:**
- Order fulfilled: January 15
- Payment term: 30 days
- **Payment due: February 14** (Jan 15 + 30 days)

**Available durations:** 7, 15, 20, 30, 45, 60, 90 days

**When to use:**
- Simple, straightforward payment terms
- Common for B2B transactions
- Easy for buyers to understand

### End-of-Month (EOM) Payment Terms

Payment is due at the **end of the current month (at fulfillment) plus X days**.

**Example:**
- Order fulfilled: January 15
- Payment term: EOM+30
- Calculation: End of January (Jan 31) + 30 days
- **Payment due: February 28** (or Feb 29 in leap years)

**Available durations:** 30, 45, 60 days only

**When to use:**
- Aligns with monthly accounting cycles
- Common in industries with monthly billing
- Simplifies payment tracking for buyers with multiple orders

**Display:**
- Admin: "End of Month + 30 days"
- Checkout: "Pay in 30 days from end of month"

**How it works:**
1. Two's backend calculates the end of the month when the order is fulfilled
2. Adds the specified days to that date
3. Buyer receives invoice with the calculated due date

---

## How It Works

### Checkout Flow

#### 1. Address Step (Business Accounts)
- Customer types at least 3 characters in the `Company` field
- Module searches Two's Company API v2 (frontend call)
- Customer selects a company from search results
- Module stores organization number in hidden `companyid` field
- Address fields auto-fill when available from Two's data
- Selection persists in cookie to survive checkout step changes

#### 2. Payment Step
- Two payment option appears for business accounts
- When selected, module runs Order Intent check (frontend)
- **If Approved**:
  - Success message displayed
  - Payment terms selector shown
  - Customer can select payment term (e.g., 30 days)
  - Order can proceed
- **If Declined**:
  - Error message displayed
  - Payment method blocked
  - Customer must choose alternative payment

#### 3. Order Confirmation
- Customer clicks "Place Order" with Two selected
- Module verifies Order Intent server-side (defense-in-depth)
- Module creates Two order first (provider-first)
- If Two rejects, checkout stops and no PrestaShop order is created
- If Two verifies, module creates PrestaShop order from callback and saves payment data
- Customer is redirected to native PrestaShop order confirmation page

### Order Management

#### Order Fulfillment

**How It Works:**
- Fulfillment is triggered automatically when you change the PrestaShop order status to one of your configured fulfillment statuses (default: "Shipped")
- The module calls Two's fulfillment API endpoint (`/v1/order/{id}/fulfillments`)
- This marks the entire order as fulfilled in Two's system
- Buyer payment terms become active and the payout cycle begins
- Order state changes from `CONFIRMED` to `FULFILLED` in Two

**Configuration:**
- Configure fulfillment trigger statuses in module settings: **Two → Configuration → Order Status Mapping → Fulfillment Statuses**
- You can select multiple statuses (hold Ctrl/Cmd to select multiple)
- Default: "Shipped" status triggers fulfillment
- The form field shows currently active statuses in green text for easy reference
- After saving, a confirmation message displays all active fulfillment trigger statuses
- Ensure "Automatically fulfill orders with Two" option is enabled (default: enabled)

**⚠️ CRITICAL: Complete Fulfillment Only**
- **The Two PrestaShop plugin only supports complete fulfillment of the entire PrestaShop order**
- **Partial fulfillments/captures are NOT supported from PrestaShop**
- When you change the order status to a fulfillment trigger status, the entire order is marked as fulfilled in Two
- If you need to fulfill orders partially, you must:
  1. Process partial fulfillment manually in PrestaShop (split orders, etc.)
  2. Then use Two's Merchant Portal to handle partial fulfillments directly

**Invoice Upload (Optional):**
- If "Use Own Invoices" is enabled in module configuration:
  - After successful fulfillment, the module automatically generates the PrestaShop invoice PDF
  - Uploads it to Two via a three-step process:
    1. **Request signed upload URL** from Two API
    2. **Upload PDF** to Google Cloud Storage using the signed URL
    3. **Poll upload status** until Two validates the invoice
  - Upload status is tracked in order metadata (`two_invoice_upload_status`)
  - Upload statuses: `PENDING`, `UPLOADING`, `UPLOADED`, `FAILED`, `NOT_APPLICABLE`
  - Check PrestaShop logs for upload progress and any errors

**Fulfillment Requirements:**
- Order must be in `CONFIRMED` state in Two (not `VERIFIED`, `CANCELLED`, or `REFUNDED`)
- Orders in `VERIFIED` state will be skipped - they must be confirmed first
- Order must have a valid Two order ID stored in PrestaShop
- Module must have valid API credentials configured

**Troubleshooting Fulfillment:**
- Check PrestaShop logs for fulfillment errors (search for "TwoPayment: Fulfillment")
- Verify order is in `CONFIRMED` state before fulfillment (not `VERIFIED`)
- If order is `VERIFIED`, it must be confirmed first (either manually in Two Merchant Portal or via Two's confirmation flow)
- Ensure "Automatically fulfill orders with Two" is enabled in module settings
- Verify fulfillment status is correctly mapped in module configuration
- Check the Order Status Mapping form to see which statuses are currently active (shown in green)

#### Refunds

**How It Works:**
- Full refunds are supported automatically via PrestaShop order status change
- When you change the order status to your configured refund status (default: "Refunded"), the module:
  - Calls Two's refund API endpoint (`/v1/order/{id}/refund`)
  - Issues a full refund for the entire order amount
  - Two immediately issues a credit note to the buyer
  - Order state changes to `REFUNDED` in Two
  - Idempotency keys prevent duplicate refund calls (race condition protection)

**Configuration:**
- Configure refund trigger status in module settings: **Two → Configuration → Order Status Mapping → Two: Order Refunded**
- Default: "Refunded" status triggers full refund
- The module checks if order is already refunded to prevent duplicate refunds

**⚠️ CRITICAL: Full Refunds Only from PrestaShop**
- **The Two PrestaShop plugin only supports full refunds via PrestaShop order status changes**
- **Partial refunds are NOT supported from PrestaShop**
- When you change the order status to the refund trigger status, the entire order is refunded in Two

**Partial Refunds - Manual Process Required:**
If you need to issue a partial refund:

1. **Process partial refund in PrestaShop** (as you normally would):
   - Go to the order in PrestaShop admin
   - Issue partial refund through PrestaShop's refund interface
   - This updates PrestaShop's order records

2. **Process partial refund in Two Merchant Portal**:
   - Log into your Two Merchant Portal
   - Find the corresponding Two order
   - Issue the partial refund manually through Two's interface
   - **This step is REQUIRED** - the partial refund will NOT be reflected in Two's system if you only process it in PrestaShop

3. **Why manual process is required:**
   - Two's API requires specific refund amounts and reasons for partial refunds
   - PrestaShop's partial refund interface doesn't provide the necessary details to Two's API
   - Manual processing ensures accurate refund amounts and proper credit note generation

**⚠️ IMPORTANT WARNING:**
- **Failing to process partial refunds in both systems will result in:**
  - Partial refund existing only in PrestaShop
  - Full order amount still owed in Two's system
  - Buyer will be charged for the full amount despite partial refund
  - Accounting discrepancies between systems
  - Potential customer service issues

**Refund Requirements:**
- Order must be in `FULFILLED` state in Two (cannot refund unfulfilled orders)
- Order must have a valid Two order ID stored in PrestaShop
- Module must have valid API credentials configured
- Order must not already be fully refunded (module checks this automatically)

**Troubleshooting Refunds:**
- Check PrestaShop logs for refund errors (search for "TwoPayment: Refund")
- Verify order is in `FULFILLED` state before attempting refund
- Common errors:
  - **HTTP 400**: Order not in refundable state (must be `FULFILLED`)
  - **HTTP 409**: Duplicate refund attempt (already refunded)
  - **HTTP 500**: Two API temporarily unavailable (retry later)

## Architecture

### File Structure
```
twopayment/
├── twopayment.php              # Main module class
├── config.xml                  # Module metadata
├── classes/
│   └── TwoInvoiceUploadService.php  # Invoice upload service
├── controllers/
│   └── front/
│       ├── payment.php         # Payment processing
│       ├── confirmation.php     # Order confirmation
│       ├── cancel.php          # Order cancellation
│       └── orderintent.php     # Order Intent AJAX
├── views/
│   ├── css/
│   │   └── two.css            # Module styles
│   ├── js/
│   │   ├── twopayment.js      # Main JS
│   │   └── modules/           # Modular JS components
│   └── templates/
│       └── hook/
│           └── paymentinfo.tpl # Payment UI template
└── upgrade/                   # Version upgrade scripts
```

### Key Components

- **Twopayment**: Main module class handling hooks, configuration, API calls
- **TwoInvoiceUploadService**: Handles invoice PDF upload to Two
- **TwoCheckoutManager**: Frontend checkout flow management
- **TwoOrderIntent**: Order Intent validation (client-side)
- **TwoCompanySearch**: Company search functionality

## Developer & AI Quickstart

### Start Here

- [AI_CONTEXT.md](AI_CONTEXT.md): AI operating manual (architecture, invariants, pitfalls)
- [AGENTS.md](AGENTS.md): repository guardrails for any coding agent
- [tests/README.md](tests/README.md): test coverage and execution details
- [CHANGELOG.md](CHANGELOG.md): behavior/history reference

Compatibility note:
- [CLAUDE.md](CLAUDE.md) is a pointer to `AI_CONTEXT.md` for tooling compatibility only.

### Mandatory Invariants

- Never create a local PrestaShop order if Two rejects order creation or verification.
- Keep provider-first checkout flow and retry idempotency intact.
- Apply rejection safeguards globally (not country-specific).
- Keep tax and amount formulas aligned with PrestaShop totals and test expectations.
- Update translation surfaces (`$this->l`, JS i18n map, `translations/es.php`) for user-facing text changes.

### Minimum Verification Before Commit

```bash
php -l twopayment.php
php tests/run.php
```

If you modified more PHP files, lint each touched file as well.

## API Integration

### Endpoints Used
- `/v1/merchant/verify_api_key` - API key validation
- `/v1/order_intent` - Order Intent check
- `/v1/order` - Order creation
- `/v1/order/{id}` - Order updates, refunds
- `/v1/invoice/{id}/upload` - Invoice upload initiation
- `/companies/v2/company` - Company search

### Order Payload
The module builds order payloads that exactly match PrestaShop invoices:
- Line items with exact net, tax, and gross amounts
- Tax subtotals matching PrestaShop calculations
- Product names including attributes (e.g., "Shirt (Size: S - Color: White)")
- Shipping and discount line items
- Buyer and shipping addresses
- Payment terms

## Troubleshooting

### Company Search Not Working
- **Symptom**: No results when typing company name
- **Solutions**:
  - Ensure at least 3 characters typed
  - Check browser console for API errors
  - Verify network calls to `/companies/v2/company`
  - Check API key is valid
  - Verify country selection (if applicable)

### Order Intent Not Firing
- **Symptom**: Order Intent check doesn't run when Two selected
- **Solutions**:
  - Verify Two payment radio button is selected
  - Check browser console for JavaScript errors
  - Ensure payment section isn't re-rendered by theme without events
  - Module listens to PrestaShop `updatedPaymentForm` event
  - Check PrestaShop logs for server-side errors

### "Order Cannot Be Processed with Two"
- **Symptom**: Order creation fails with error message
- **Solutions**:
  - Check PrestaShop logs for server-side Order Intent errors
  - Verify company data persisted (cookie and hidden `companyid` field)
  - Ensure Order Intent was approved (check cookie `two_order_intent_approved`)
  - Verify API key is correct for environment
  - Check Two API status

### Invoice Upload Failing
- **Symptom**: Invoices not uploading to Two
- **Solutions**:
  - Verify "Use Own Invoices" option enabled in module config
  - Check PrestaShop logs for upload errors
  - Verify PDF generation works (test invoice download)
  - Check file size limits (max 2MB)
  - Verify SSL certificate issues (if corporate network)

### Payment Terms Not Showing
- **Symptom**: Payment terms selector not visible
- **Solutions**:
  - Verify at least one payment term enabled in config
  - Check Order Intent was approved
  - Verify JavaScript loaded correctly
  - Check browser console for errors
  - Ensure company is selected (not just typed) - search and click a result

### "Invalid Phone Number" Error
- **Symptom**: Order fails with phone validation error
- **Solutions**:
  - Ensure customer has entered a valid phone number in billing address
  - Module tries both `phone` and `phone_mobile` fields automatically
  - Phone must be valid for the selected country
  - Check billing address has a phone number filled in

### "Company Details Required" Message
- **Symptom**: Two payment shows message asking to provide company details
- **Solutions**:
  - Customer must enter company name in the billing address Company field
  - Customer must search and **select** their company from the dropdown results
  - Simply typing a company name is not enough - must click to select from search
  - If using an existing address, customer should edit it to add/verify company

### Tax Rate Issues (0% Tax)
- **Symptom**: Two API rejects order with tax rate error
- **Solutions**:
  - Enable Debug Mode in module settings (Other Settings → Enable Debug Mode)
  - Check PrestaShop logs for "TwoPayment: Product tax debug" entries
  - Verify products have correct tax rules assigned in PrestaShop
  - Module now calculates tax from actual amounts as fallback
  - Contact Two support with debug logs if issue persists

### Debug Mode
- **When to use**: Only enable when requested by Two support for troubleshooting
- **What it logs**: 
  - Tax calculations per product (rate field, net/gross amounts, calculated rate)
  - Helps diagnose tax rate discrepancies between PrestaShop and Two API
- **How to enable**: 
  1. Go to Module Configuration → Other Settings
  2. Toggle "Enable Debug Mode" to Yes
  3. Save settings
  4. Reproduce the issue
  5. Check PrestaShop logs (`var/logs/`)
  6. Disable Debug Mode when done

## Security

### Best Practices Implemented
- ✅ SSL certificate verification (configurable override for corporate networks)
- ✅ SQL injection prevention using PrestaShop standards
- ✅ Input validation and sanitization
- ✅ Server-side Order Intent verification
- ✅ Rate limiting on API calls
- ✅ Secure API key storage
- ✅ CSRF token validation for AJAX requests

### Security Recommendations
- Always use HTTPS in production
- Keep module updated to latest version
- Regularly rotate API keys
- Monitor PrestaShop logs for suspicious activity
- Use strong API keys (provided by Two)

## Support

### Documentation
- Module documentation: See this README
- Two API documentation: Contact Two support
- PrestaShop documentation: https://devdocs.prestashop.com/

### Getting Help
- **Technical Issues**: Check PrestaShop logs (`var/logs/`)
- **API Issues**: Check Two API status and logs
- **Module Bugs**: Contact Two support at support@two.inc
- **Onboarding**: Contact Two support for production enablement

### Logging
Module logs to PrestaShop's standard logging system:
- **Location**: `var/logs/[date].log`
- **Levels**: Info (1), Warning (2), Error (3), Major (4)
- **Search**: Look for "TwoPayment" prefix

## Version History

See [CHANGELOG.md](CHANGELOG.md) for detailed version history and changes.

## License

Two Commercial License

## Copyright

© 2021-2026 Two Team
