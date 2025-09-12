# Two for PrestaShop — B2B Buy Now, Pay Later

## Synopsis
### Overview
Two is a B2B payment method that lets your business customers pay by invoice with instant credit decisioning. This module integrates Two with PrestaShop 1.7.6+ following PrestaShop best practices.

### What this module provides
- Two payment option visible for business accounts at checkout
- Company search and select (frontend) against Two’s Company API
- Hidden organization number capture (`companyid`) from the selection
- Frontend Order Intent check against Two before payment confirmation
- Server-side verification of Order Intent at payment submit (defense-in-depth)
- Payment terms UI (configurable) after approval
- Admin order info (Two order id, state, status, invoice URL)

### Requirements
- PrestaShop 1.7.6+
- PHP 7.2+
- Classic theme (or 1.7-compatible themes)
- Store must support B2B (business account type at address step)

### Configuration (Back Office)
1. Install and enable the module.
2. In module settings, choose environment (Sandbox/Production) and configure payment terms (e.g., 7/15/30/45/60/90 days and default).
3. Set your API key against your chosen environment and save your configuration to validate your credentials.

### How it works (Checkout)
1. Address step (business accounts):
   - Type at least 3 characters in the `Company` field to search Two’s Company API (frontend).
   - Select a company. The module stores its organization number in a hidden `companyid` field and autofills address when available.
   - Selection is persisted in a cookie to survive step changes.
2. Payment step:
   - When the Two payment option is selected, the module runs an Order Intent check (frontend) against Two.
   - If approved, the Two payment info shows success and payment terms UI.
   - If declined, a helpful message is shown and the method is blocked.
3. Confirming payment:
   - On clicking Place Order with Two selected, the module verifies Order Intent again server-side, then creates the order and redirects to Two if required.

Notes:
- Company search and order intent are called from the browser.
- The server uses the same company data for a final verification at submit time.

### What you can and cannot do
Can:
- Offer Two to business customers with instant approval checks
- Search and select companies across supported countries (using selected country ISO when available)
- Configure available and default payment terms
- See Two status and links in Admin Order pages

Cannot (by design):
- Use Two for personal non business accounts

### Troubleshooting
- Company search not showing results: ensure you type 3+ chars; check browser console/network for calls to `.../companies/v2/company`.
- Order intent not firing: verify the Two payment radio is selected; check console for `TwoOrderIntent` errors; ensure the payment section isn’t being re-rendered by a theme without events (module listens to PrestaShop `updatedPaymentForm`).
- “Your order cannot be processed with Two”: check PrestaShop logs for server-side intent errors; verify the selected company is persisted (cookie and hidden `companyid`).


### Support
For onboarding and production enablement, contact Two support at support@two.inc.