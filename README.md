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
- Declared-rate VAT relay with fail-loud divergence checks (see [.ai/vat-rate-sourcing.md](.ai/vat-rate-sourcing.md))
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
2. **Environment Selection**: Choose Staging (testing) or Production environment
3. **API Key**: Enter your Two API key for the selected environment
   - The module validates the API key on save
   - A key that does not verify is reported by category, so a rejected key, a Two service error and a shop that cannot reach Two at all read differently. The HTTP status is shown; the response body is only ever logged
   - While a stored key does not verify, Two is withheld from checkout entirely (payment option and company search alike) and the config page says so. The verdict is re-checked automatically - about once a minute while it is failing - so a fixed key or a resolved outage takes effect without a re-save
4. **Payment terms**: Configure payment term type and available terms
   - **Term Type**: Choose Standard or End-of-Month (EOM) terms
     - **Standard**: Payment due X days from fulfillment date (all durations available)
     - **EOM**: Payment due at end of current month + X days (30/45/60 only)
   - Enable/disable individual terms based on selected type
   - Set default payment term (defaults to 30 days if available)
5. **Optional Features**:
   - Enable/disable company name field requirement
   - Enable/disable organization number field requirement
   - Enable/disable the optional buyer reference fields shown in the Two
     payment section at checkout, in this order: invoice email address,
     PO Number, project, department (all enabled by default on a fresh
     install)
   - Enable/disable Order Intent check (Required for use)
   - Enable/disable account type selection
   - Enable automatic invoice upload to Two
   - Configure SSL verification (default: enabled)

### Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| Environment | Staging or Production | Staging |
| API Key | Two merchant API key | Required |
| Payment Term Type | Standard or End-of-Month (EOM) | Standard |
| Payment terms | Available terms based on type | 30 days enabled |
| Default Payment Term | Default term when multiple available | 30 days |
| Company Name | Require company name field | Enabled |
| Organization Number | Require organization number | Enabled |
| Invoice email Field | Show invoice email address field in the Two payment section | Enabled |
| PO Number Field | Show PO Number field in the Two payment section | Enabled |
| Project Field | Show project field in the Two payment section | Enabled |
| Department Field | Show department field in the Two payment section | Enabled |
| Order Intent | Enable Order Intent check | Enabled |
| Account Type | Show account type selector | Enabled |
| SSL Verification | Verify SSL certificates | Enabled |
| Debug Mode | Enable detailed diagnostic logging | Disabled |
| Default shipping tax code | Tax rules group assumed, and checked, for shipping no carrier provides a rate for. Hidden until Two enables it — see below | Not set |

### Optional buyer reference fields

Four optional fields can be shown to the buyer, each with its own switch, all
**enabled by default on a fresh install**. They appear in one standard order,
used by both the admin switches and the checkout fields so the configuration
pane reads like the thing it configures:

1. Invoice email address — sent as `invoice_details.invoice_emails`
2. PO Number — sent as `buyer_purchase_order_number`
3. Project — sent as `buyer_project`
4. Department — sent as `buyer_department`
5. Order note — **PrestaShop core's field, not one of ours** (see below); sent
   as `order_note`

An empty field is simply not sent, and none of them is ever required.

Because the order note is core's field on a different checkout step, it has no
presence in the payment tile and no switch: it is fifth in the standard
sequence, but there is nothing to sort it against there. Adding a plugin
order-note field just to express the ordering would be the wrong fix.

They render **inside the Two payment section at the payment step**, not in the
billing address block. PrestaShop asks for the shipping address first and only
reveals the billing address block when the buyer ticks "Billing address differs
from shipping address", so a field hosted there is invisible to most buyers.
The invoice email in particular has to be visible in the case where billing and
shipping match, which is exactly when the buyer should be prompted to consider
a separate address for invoices.

A switch that is off renders no element at all — not a hidden one — and its
value is never read from the request even if the parameter is supplied by hand.

**Order comments** are deliberately not a plugin field. PrestaShop core already
offers one: the "If you would like to add a comment about your order" textarea
(`name="delivery_message"`) on the checkout **shipping** step, rendered by
`checkout/_partials/steps/shipping.tpl` and stored by core one row per cart in
the `message` table. Use that; do not add a plugin equivalent.

The module **relays** that value to Two as `order_note` on both order creation
and order update, capped at 1000 characters. It is read from the cart rather
than from the buyer's submission, which is what lets the update payload carry it
too — read from the request, an admin order edit would blank the note on Two's
side. Core stores the comment htmlentities-encoded (`Tools::safeOutput`), so the
relay decodes it back to plain text.

### Default shipping tax code

**Who needs this:** shops that price shipping outside PrestaShop's carrier table — third-party carrier modules, click-and-collect, marketplace shipping, or any other carrier-less setup that never registers a tax rules group.

PrestaShop declares shipping VAT per carrier, in `carrier_tax_rules_group_shop`, and nowhere else — there is no shop-level shipping tax rules group. The module relays that declaration; it never derives a VAT rate from the amounts. A shop whose shipping is priced outside the carrier table leaves `id_carrier = 0`, PrestaShop then hands the module an empty delivery-option list, and with no carrier there is no declared rate to relay.

The **Default shipping tax code** setting, in **Module Configuration → Order management**, lets such a merchant make that declaration on the module instead. It is **off, and the field hidden, until Two enables it for the shop**: talk to Two first, since assuming a shipping rate is a tax decision we want to review with you. It is assumed **for shipping only, and only when no carrier provides a rate**. When a carrier declares a tax rules group, the carrier always wins.

The setting is the module's only control over shipping tax, and it has an effect only when it is populated: enabled, and set to a group that resolves. Blank, the default, the module never refuses an order on shipping tax and leaves the check to Two's API. A carrier that declares a tax rules group provides the rate, an explicit 0% group included. "No tax" (group 0), no carrier, or a carrier that cannot be read provides none, whatever tax the line carries.

| Shipping line | Setting blank (the default) | Setting populated |
|---|---|---|
| Rate provided by a carrier, 0% included | Sent at that rate as is, with no module check. | Same as blank. |
| No rate provided | Sent as is: rate 0, with the tax it was charged, and no module check. | Sent at the setting's rate, once its tax reconciles with that rate (to 0.02); otherwise the order is refused. |

The module decides the rate while it builds the payload, and records at placement whether a carrier provided it. Order updates and refunds read that record, never the carrier or the setting as they are later. The check against the setting is a shop-match check: the module's default handler on the order postprocessing hook runs it, and a merchant handler on the hook owns it instead (see "The module's checks" under "Stable extension contract: order postprocessing"). The setting has **no default value**.

#### Enabling it

Two enables the fallback with the module's console command, run from the PrestaShop root by someone with shell access to the server (PrestaShop 1.7.6 and later). Without `--shop`, `enable` and `disable` write the global setting, which applies to every shop. With `--shop=<n>` they write that shop's own setting, which wins over the global one, so a multistore merchant can have the fallback on one shop only:

```bash
php bin/console twopayment:shipping-tax-fallback enable              # every shop: show the field and use the fallback
php bin/console twopayment:shipping-tax-fallback enable --shop=2     # shop 2 only
php bin/console twopayment:shipping-tax-fallback disable --shop=2    # shop 2 off, whatever the global setting says
php bin/console twopayment:shipping-tax-fallback disable             # the global setting off; a shop's own setting still wins
php bin/console twopayment:shipping-tax-fallback status              # each shop's effective state, where it comes from, and its stored group
```

Every action ends by printing each shop's effective state. On PrestaShop 1.7.6 to 8, core's own `--id_shop=<n>` is accepted as a synonym for `--shop=<n>`; `--id_shop_group` and a bare `--id_shop` are refused. PrestaShop 9 does not offer `--id_shop`, so use `--shop` there.

The switch is stored in configuration as `PS_TWO_SHIPPING_TAX_FALLBACK_ENABLED`: the global row, or one row per shop. An order reads the setting of the shop its cart belongs to. If the command is not listed (`There are no commands defined in the "twopayment" namespace`), clear the cache with `php bin/console cache:clear` so PrestaShop picks up the module's services. That is needed after the module's files are updated on a shop whose cache is already built.

While it is disabled, the setting is blank for every order, and a group already stored is kept but not used. Saving the Order management tab never changes that stored group while the field is hidden, so enabling again restores the earlier selection.

Notes:

- Selecting a group that is later deleted is treated as "not set", and the shop log says so.
- Every order that actually uses the fallback writes a warning to the shop log naming the group, its id and the resolved rate, e.g. `assuming the configured Default shipping tax code "IVA 21%" (tax_rules_group=12, rate=21%)`. If you never see that line, the fallback is not being used.

### Tax codes for 0% lines

Two requires a `tax_code` on every line at a 0% rate for a Spanish merchant, on order create, order update and refunds alike. The module sends one on each 0% line it builds (products, shipping, gift wrapping, the buyer fee, ecotax and discount lines) in two ways. **The plugin never refuses an order over a tax code; the API does.** A line the module cannot code goes out without one, and Two's API validates what arrives. The module never picks `ES_IVA_EXEMPT_OTHER`, `ES_IGIC_ZERO` or `ES_IPSI_ZERO` itself, and never changes a rate. Lines at any other rate carry no code and are sent exactly as before.

**1. The mapping.** In **Module Configuration → Order management → Tax codes for 0% lines**, each of the shop's tax rules groups has a dropdown of the codes Two lists for the merchant's country (`GET /v1/tax_codes/<country>`, cached for a day), plus **(none)**, the default. A 0% line taxed by a mapped group carries its code, for any merchant country. A product line maps by the product's tax rules group; shipping by the group that supplied its rate: the Default shipping tax code's, or the carriers' when every carrier in the selected delivery option declares the same group; wrapping by the gift-wrapping group; ecotax by the ecotax group; the buyer fee by its own group. A "No tax" carrier, no carrier, or carriers declaring different groups give no group, so only the derivation applies. Codes that need a reason from the caller (today only `ES_IVA_EXEMPT_OTHER`) are not offered: a merchant who needs one sets the code and its `tax_exemption_reason_code` in the order postprocessing hook. If the list cannot be fetched, the section shows why (retrying at most every five minutes), and saved mappings keep working at checkout. A stored mapping the form could not have written withholds the payment method at checkout, and the configuration health panel names it; an order with no 0% line never reads the mapping.

**2. The derivation**, for a Spanish merchant and an unmapped line. A product is a service when it is virtual, and goods otherwise; a shipping, wrapping, fee or discount line counts as goods when the order holds any physical product, and as a service otherwise. Goods follow the delivery address (the invoice address when there is none); services follow the buyer company's country, the country the module sends as `buyer.company.country_prefix`.

| Line | Condition | Code |
|---|---|---|
| Goods | Delivered outside the EU (Monaco counts as France) | `ES_IVA_EXPORT` |
| Goods | Delivered to the Canary Islands (postcodes 35, 38), Ceuta (51) or Melilla (52) | `ES_IVA_EXPORT` |
| Goods | Delivered to another EU country, for a buyer company in another EU country (not necessarily the same one) | `ES_IVA_INTRA_COMMUNITY` |
| Goods | Anything else: mainland Spain or the Balearics, or an EU destination with a Spanish buyer | none |
| Service | Buyer company in another EU country | `ES_IVA_REVERSE_CHARGE` |
| Service | Buyer company in Spain or outside the EU | none |

A 0% discount line takes the code its order's other 0% lines share; when they do not share one, it follows the goods like a charge. The code is set while the module builds the payload, so a postprocessing hook subscriber sees it and may change it.

Placement records the codes it resolved on the order's Two row. An order update sends those, not what today's mapping or addresses would give; a line placement did not code (a product added in an admin edit, or an order placed before this feature) is resolved when the update is built. Refunds need no code from the module: a full refund copies the order's lines at Two, and each line of a partial refund references the order line it refunds, so Two gives it that line's code.

### Merchant profile refresh

Your offerable payment terms, buyer-surcharge rates, minimum order value and default term are read from Two's merchant record and cached in the shop's configuration. Checkout and admin pages read that cache and never block on the API.

**The cache never expires and is never cleared.** A failed refresh — an outage, a rejected key, a 500, an unreachable API — changes nothing: the shop keeps trading and its admin pages keep showing the last known good values. Saving a new API key or environment refreshes the record but does not clear it either, so a shop whose key was cycled elsewhere and never updated here does not lose the values its admin controls are built from, and a wrong key saved during an outage is undone by pasting the right one back. A record belonging to a key the shop no longer holds is not used, but it is kept until a refresh succeeds.

The cache is replaced only by a successful refresh, and only from one of these:

- the first read on a shop where no fetch has ever succeeded (a fresh install), retried at most every 5 minutes until one does;
- a save of the API key or the environment;
- the scheduled refresh (below);
- **Refresh merchant profile**, in **Module Configuration → Diagnostics**.

If the record is more than 26 hours old, the scheduled refresh is not running. A page read then refreshes it itself, at most once an hour and on a 2-second cap, and serves the record it already holds whichever way that goes. The Diagnostics tab reports when the record was last refreshed and whether page reads are having to stand in.

**Multistore:** the cached record belongs to one shop, so **Refresh merchant profile** and the line above it appear only while the admin is scoped to a single shop.

The record never decides whether Two appears at checkout. Only the API key check does that: if the stored key does not currently verify, for any reason, the payment method is withheld until it does.

#### Scheduled refresh

PrestaShop schedules nothing on a module's behalf, so the scheduled refresh is a URL your server's crontab calls. Copy it from **Scheduled refresh URL** in **Module Configuration → Diagnostics** and add one line to the crontab of the user that owns the shop:

```
0 3 * * * curl -fsS -o /dev/null 'https://your-shop.example/module/twopayment/cron?token=YOUR_TOKEN'
```

The token is the only thing guarding the endpoint — treat it as a credential. A request without it, or with the wrong one, gets a 404 that says nothing about why; rejections are logged at most once an hour, so a scanner cannot flood the shop log.

Accepted calls are floored at **one refresh per 15 minutes**: anything sooner gets a 429 and does no work, so holding the token cannot drive one blocking outbound request per call. A once-a-day cron never reaches the floor.

To keep the token out of process listings and proxy access logs, send it as a header or a POST field instead of in the query string:

```
0 3 * * * curl -fsS -o /dev/null -H 'X-Two-Cron-Token: YOUR_TOKEN' 'https://your-shop.example/module/twopayment/cron'
```

**Multistore:** the cached record and the token both belong to one shop, so schedule **one line per shop**, each copied from that shop's own Diagnostics tab. The URL and the token are shown only while the admin is scoped to a single shop; in the all-shops or a group context the row says so instead.

**Maintenance mode:** PrestaShop serves the maintenance page before a module front controller runs, so while the shop is closed this URL does nothing unless the calling server's IP is listed under **Shop Parameters → General → Maintenance**.

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

EOM is not part of the general rollout, so the **Payment terms type** selector is only shown on a shop already configured for EOM — one whose stored term type is `EOM`. On every other shop the field is absent and terms are Standard. Saving **Standard terms** switches the shop back and the selector disappears with it; a later configuration write of `EOM` brings it back.

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
- Customer types at least 3 characters in the `Company` field; below that the dropdown says `Please enter 3 or more characters` rather than staying shut. The threshold is a single constant in `views/js/modules/TwoCompanySearch.js` and is interpolated into that message, so the number shown always matches the number enforced
- Module searches Two's Company API v2 (frontend call)
- Customer selects a company from search results
- Module stores organization number in hidden `companyid` field
- Address fields, DNI and VAT number auto-fill from Two's data when available, and a re-search overwrites them with the newly selected company's values. Merchant-configurable (Company Lookup -> "Autofill company address", enabled by default); with it off, the company search still records the company name and organisation number but writes nothing into the address step
- Selection persists server-side against the cart to survive checkout step changes. Cart-scoped company and mirror-address data live in the module's `twopayment_cart_record` table, keyed by cart and shop. A cart's records are deleted when its Two order is confirmed. On the first storefront request at least 24 hours after the last run, a purge also deletes the records of every cart ordered in its shop by any payment method, and every record not updated for 90 days. A GDPR erasure request made through the official GDPR module (psgdpr) deletes the customer's records, and its data export includes them. The cookie carries none of it, so nothing a buyer types can break or overflow the cookie

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

#### Order updates

An order update (a back-office edit, a tracking number, the sync after checkout) carries the order exactly as PrestaShop currently records it, from its stored rows, and never from the live catalogue, cart or configuration. The Two order it updates is the whole Two order: a cart whose products ship with different carriers is split by PrestaShop into several orders sharing one reference, and the update carries all of them, whichever of them was edited. Each line's amounts and tax rate come from the order's own lines and the taxes recorded for them, shipping as each order's paid total charged it, discounts from the order's recorded vouchers, and gift wrapping from the order's totals. A catalogue price, tax rule, carrier price or voucher changed after placement therefore changes nothing at Two, while a back-office edit to the order itself does. Any rate used must agree with the stored amounts, or the update fails with a log entry, except for shipping, which follows the Default shipping tax code table from what placement recorded. Shipping a carrier provided a rate for takes the carrier rate recorded on the order, then the rates declared at placement, and if neither agrees it goes out as it is, at the declared rate, else the order's carrier rate. Shipping no carrier provided a rate for takes the Default shipping tax code's rate recorded at placement, which must agree, or with none recorded goes out at 0% with the tax charged. A carrier-less order records no carrier rate. An order placed before the module kept that record also tries the Default shipping tax code as it is now configured. Gift wrapping takes the rate its invoice recorded (invoices disagreeing on it fail the update), then the rate declared at placement, then the configured rate. An order that records no shipping sends no shipping line, even when rounding the total leaves a cent over. A back-office edit sets the order's payment to the total of the orders it pays, and only when that payment is a single one recorded by this module; a split payment or another method's payment is left alone. An update whose amounts, lines, buyer, addresses, carrier and tracking number match the last one Two accepted is not sent at all, so saving an unchanged tracking number, or editing catalogue text, makes no request.

#### Buyer surcharge on order updates

An order update (a back-office edit, a tracking number) replays the buyer surcharge exactly as PrestaShop currently records it on the order. Its amounts and rate come from the stored order data, never from the live surcharge configuration, so changing the surcharge settings, tax rules or tax treatment later does not change an existing order's fee at Two. Rates stacked one after another are compounded, as PrestaShop applied them. The fee is recognised under any product id the hidden fee product has had, so recreating or deleting that product does not orphan older orders (see [Recognising the fee row](#recognising-the-fee-row)). The fee is priced on the order the module built, before the order postprocessing hook runs, so a merchant's subscriber that changes the order does not change the fee (see [The buyer fee is priced before the hook](#the-buyer-fee-is-priced-before-the-hook)).

PrestaShop's own admin actions can rewrite that record: on 8 and 9 an address change re-taxes every order line from the live rates, and on 1.7 editing a line re-taxes it from the product's live tax group. The update then sends what PrestaShop now holds.

Where the stored data cannot be replayed as recorded (fee lines at different rates, amounts that disagree with the recorded rate, or a row carrying the fee reference under an id the fee never had), the update fails loudly with a `TWO-26076` entry in the shop log rather than send the order without its fee.

When an order edit or a tracking number does not reach Two (its payload cannot be built, or Two rejects it), the change stays saved in PrestaShop and the order is marked as not sent. PrestaShop itself reports the save as a success: on 1.7 an edit returns core's own AJAX result and a tracking number redirects to "Successful update."; on 8 and 9 an edit returns core's own JSON and a tracking number redirects with core's own flash. So on every version the admin sees the failure on the order page, from its next load (at once after a tracking number, on reload after an edit):

- a red panel at the top of the Two payment block: "Changes to this order since `<time>` UTC were saved in PrestaShop but have not reached Two, so its invoice may not match this order. Do not repeat an edit to retry it; please contact support."
- a private message in the order's Messages for each failure, giving the reason.

The panel stays until an edit or tracking number update is accepted by Two.

An order edit or a tracking number is not sent at all once Two would refuse it. Before sending, the module reads the order's current state from Two, with a 10-second timeout, and sends the update only while that state is `UNVERIFIED`, `VERIFIED` or `CONFIRMED` and its status is `APPROVED`, `REJECTED` or `DECLINED`. Otherwise the order gets a private message giving the reason, either "Two has already invoiced all or part of this order, so this change was not sent to Two." (a full or partial fulfilment) or "Two no longer accepts changes to this order (`<state>`), so this change was not sent to Two." (for example a cancelled order). An order edit that is not sent also marks the order as not sent, as above, since its amounts in PrestaShop now differ from Two's. As Two will accept no later edit to such an order, the red panel then stays for good unless the order is edited back to what Two last accepted. A tracking number that is not sent leaves no panel, as it changes no amounts. If the state cannot be read, the update is sent as before.

#### Recognising the fee row

A cart or order row is the surcharge fee if and only if its product id is the current or a retired fee product id AND its reference (cart row `reference` / order_detail `product_reference`) equals `TWO_SURCHARGE_PRODUCT_REFERENCE`.

Every place the module tells the fee apart from merchandise (the order payload, the cart and order parity checks, the update replay, the cart display and the order-row guard) applies this one test, `Twopayment::isTwoSurchargeRow`. An id alone is not enough: MySQL can hand a retired fee id to a new catalog product, and that product must still be sold.

Retired ids are recorded whenever the fee product is replaced. Ids from before that recording began are seeded once, by the 2.7.16 upgrade (or on first use where core never ran it), from every `product_id` that `order_detail` holds under the fee reference other than the current one. An order update that meets the fee reference under any other id fails loudly rather than send the order without that fee.

#### Order Fulfillment

**How It Works:**
- Fulfillment is triggered automatically when you change the PrestaShop order status to one of your configured fulfillment statuses (default: "Shipped")
- The module calls Two's fulfillment API endpoint (`/v1/order/{id}/fulfillments`)
- This marks the entire order as fulfilled in Two's system
- Buyer payment terms become active and the payout cycle begins
- Order state changes from `CONFIRMED` to `FULFILLED` in Two

**Configuration:**
- Configure fulfillment trigger statuses in module settings: **Two → Configuration → Order management → Fulfillment Statuses**
- You can select multiple statuses (hold Ctrl/Cmd to select multiple)
- Default: "Shipped" status triggers fulfillment
- The form field shows currently active statuses in green text for easy reference (red "None selected" if the selection is empty, which means fulfilment never fires)
- After saving, a confirmation message displays all active fulfillment trigger statuses

**⚠️ CRITICAL: Complete Fulfillment Only**
- **The Two PrestaShop plugin only supports complete fulfillment of the entire PrestaShop order**
- **Partial fulfillments/captures are NOT supported from PrestaShop**
- When you change the order status to a fulfillment trigger status, the entire order is marked as fulfilled in Two
- If you need to fulfill orders partially, you must:
  1. Process partial fulfillment manually in PrestaShop (split orders, etc.)
  2. Then use Two's Merchant Portal to handle partial fulfillments directly

**Invoice Upload (Optional):**
- Gated solely on the merchant's server-side `invoice_distributed_by_merchant` flag, read
  from the cached `GET /v1/merchant` record. There is no admin toggle. When the flag is set:
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
- Configure refund trigger status in module settings: **Two → Configuration → Order management → Two: Order Refunded**
- Default: "Refunded" status triggers full refund
- The module checks if order is already refunded to prevent duplicate refunds
- Two refunds an order only once it is fulfilled. If the status changes to Refunded before then (for example while Two is still `FULFILLING` the order just after it shipped), no refund is sent: the order page and the order's private notes say so, and the refund is then made in the Two Merchant Portal

**Partial Refunds (Credit Slips):**
- Partial refunds created as credit slips in PrestaShop are sent to Two automatically
- When you issue a partial refund from the order page in PrestaShop admin, PrestaShop creates a credit slip and the module:
  - Calls Two's refund API endpoint (`/v1/order/{id}/refund`) with the slip's amount, currency, per-rate tax subtotals and line items
  - Uses an idempotency key derived from the credit slip, so two slips of the same amount on one order are separate refunds
  - Refuses to send more than the order's remaining refundable balance
- A specific-amount refund sends the amount you chose, and a refund excluding the voucher sends the products less the voucher
- **Do NOT also refund the same amount in the Two Merchant Portal.** The refund already reaches Two from the credit slip, so refunding it in the portal as well refunds the buyer twice
- Changing the order status to Refunded after credit slips were sent refunds only what is left of the order
- Each partial refund, a credit slip or what is left, is sent as lines of the order as Two holds it, so the credit note lists what was refunded and each line keeps its order line's tax code (Two requires one on a Spanish 0% line, and an e-invoiced credit note needs lines). Each tax rate's share goes to the order's lines at that rate: a slip's products to the product lines and its shipping to the shipping lines, then in proportion to what is left to refund on each line, and no line is credited more than is left on it. A specific amount or a voucher deduction is spread the same way. A refund that cannot be matched to the order's lines (no line at one of its rates, less left on that rate's lines than the refund, tax that differs from the rate by more than 0.50 (such as tax refunded at 0%), or a 0% line placed without a tax code on the order of a Spanish merchant, or of a merchant whose country is not yet known) is sent as before, with no lines: it is logged under "without line items", and the order's private notes say the credit note may not be itemised
- A slip the module does not send (for example, one exceeding the remaining balance, or one whose amount cannot be split across the order's tax rates) is logged under "TwoPayment: Partial refund" with the reason, and the order page and the order's private notes say it was not sent. Handle a refund in the Two Merchant Portal only when the module says it was not sent

**Refund Requirements:**
- Order must be in `FULFILLED` state in Two (cannot refund unfulfilled orders)
- Order must have a valid Two order ID stored in PrestaShop
- Module must have valid API credentials configured
- Order must not already be fully refunded (module checks this automatically)

**Troubleshooting Refunds:**
- Check PrestaShop logs for refund errors (search for "TwoPayment: Refund" and "TwoPayment: Partial refund")
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
│   ├── TwoCheckoutAmountException.php   # Fail-loud amount/rate divergence
│   ├── TwoInvoiceRetrievalService.php   # Invoice fetch/download
│   ├── TwoInvoiceUploadService.php      # Invoice upload service
│   ├── TwoSoleTrader.php                # Sole-trader gating
│   └── TwoSurchargeCalculator.php       # Buyer surcharge amounts
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
│       └── hook/             # Checkout + admin-order templates
├── tests/                    # Offline harness, e2e (Playwright), integration matrix
├── dev/                      # Local-stack and CI helper scripts
├── .ai/                      # Engineering notes, decisions, learnings
├── Makefile                  # Local dev + the gates CI runs
└── upgrade/                  # Version upgrade scripts
```

### Key Components

- **Twopayment**: Main module class handling hooks, configuration, API calls
- **TwoInvoiceUploadService** / **TwoInvoiceRetrievalService**: Invoice PDF upload and fetch
- **TwoSurchargeCalculator**: Buyer surcharge amounts
- **TwoCheckoutManager**: Frontend checkout flow management
- **TwoOrderIntent**: Order Intent validation (client-side)
- **TwoCompanySearch**: Company search functionality
- **TwoOptionalFields**: Optional buyer reference fields in the payment tile (client-side)

## Versioning and upgrade scripts

The version is computed from the change itself, not from the branch it lands on:

| Change                | What happens                                                                                      |
| --------------------- | ------------------------------------------------------------------------------------------------- |
| PR into `staging`     | The version is computed and committed onto the PR's own branch — `.github/workflows/version-bump.yml` |
| merge into `staging`  | Nothing. The merge brings in the version its PR already computed.                                 |
| `staging` into `main` | Nothing is computed. `main` tags the version already in the tree and cuts the Release.             |

With `M` the version on `origin/main` and `C` the version on the PR head, the
PR's own commits (`origin/staging..HEAD`, `--no-merges`) are classified by
conventional-commit type:

- a `!` on the type (`feat!:`, `TWO-1/fix(scope)!:`) or a `BREAKING CHANGE:`
  footer → `(M.major + 1).0.0`
- a `feat:` → `M.major.(M.minor + 1).0`
- anything else — `fix`, and `chore` / `docs` / `ci` / `test` / `refactor`
  alike → `M.major.M.minor.(M.patch + 1)`

The candidate is then clamped with `max(C, candidate)`. That clamp is what makes
the whole thing idempotent: a re-run, the `synchronize` event fired by the bump
commit itself, and a second fix commit on the same PR all compute the same
answer and write nothing. It also means the version can never regress while
`main` is behind `staging`.

**Do not hand-run a bump for a PR into `staging`.** CI owns it. `make bump`
previews the decision and writes nothing.

A major is not chosen by hand either. Two independent signals are considered and
the higher wins:

- **Declared** — a root `.next-major` file whose first token is the target
  major, with a short reason on the same line:

      3  # PrestaShop 9 only, 3.0.0 release

  This covers a _planned_ major that no single commit happens to mark. It is
  reviewable in the PR that decides it, and it is not cleared afterwards — it
  disarms itself once the major it names has shipped. A `.next-major` naming a
  major _below the major on `main`_ is a hard failure, not a no-op.

- **Discovered** — a `!` on a conventional-commit type or a `BREAKING CHANGE:`
  footer, in **this PR's own commits** only. Deliberately not the cumulative
  `main..staging` range: a break that already landed on `staging` must not be
  re-discovered by every later PR.

`.github/scripts/decide-bump-level.sh` implements all of this, is unit-tested by
`.github/scripts/test-decide-bump-level.sh`, and logs its full reasoning on every
run. It is identical in every Two plugin repository.

### Why the version is load-bearing here

PrestaShop executes `upgrade/upgrade-<version>.php` **only for versions strictly
above the one already installed**, and it derives the function to call from the
filename. Both halves fail *silently*:

- a script whose filename and `upgrade_module_X_Y_Z` function disagree is loaded
  and then nothing is called;
- a script numbered **above** the declared module version is never in range for
  any upgrade, so it never runs.

There is no error, no warning and no log line. The first symptom is a merchant
whose data was quietly never migrated. So two rules:

1. `upgrade/upgrade-X.Y.Z.php` must declare `upgrade_module_X_Y_Z()`.
2. The declared version — in **both** `config.xml` and `twopayment.php` — must
   be **at least** the highest `upgrade/` filename.

   Note the boundary: declared **equal to** the highest upgrade script is the
   normal, correct case, because a script is named for the version it upgrades
   *to*. Shipping 2.7.0 alongside `upgrade-2.7.0.php` is exactly the intended
   pattern — that script is what migrates a 2.6.x shop onto 2.7.0. Only a
   script numbered *above* the declared version is unreachable.

`tests/UpgradeScriptVersionSpec.php` gates both, and runs in the offline suite
(`php tests/run.php`).

The version sequence is legitimately **non-contiguous**: 2.6.7 was deliberately
skipped, and most releases ship no upgrade script at all because most need no
data migration. The gate does not require contiguity, and must never be changed
to.

## Developer & AI Quickstart

### Start Here

- [AI_CONTEXT.md](AI_CONTEXT.md): AI operating manual (architecture, invariants, pitfalls)
- [AGENTS.md](AGENTS.md): repository guardrails for any coding agent — the mandatory
  invariants and the pre-commit verification commands live here
- [.ai/vat-rate-sourcing.md](.ai/vat-rate-sourcing.md): how order-payload tax rates are
  sourced and where the payload fails loud
- [.ai/decisions.md](.ai/decisions.md) / [.ai/learnings.md](.ai/learnings.md): dated
  journals of record
- [tests/README.md](tests/README.md): test coverage and execution details
- [CHANGELOG.md](CHANGELOG.md): version history

### Local Development

```bash
make help       # all targets
make install    # boot a local PrestaShop with the module installed
make test       # the unit harness CI runs (php tests/run.php)
make test-js    # the Jest gate CI runs (views/js; host Node 20+, see tests/js/README.md)
make phpstan    # the static-analysis gate CI runs
make test-integration  # real-engine probes (see tests/integration/README.md)
```

#### Service URL overrides

Three of the hosts the module resolves can each be repointed with their own
environment variable. Each falls back to its own environment-keyed default when
unset — `production` and `staging` have explicit hosts, everything else
(including an empty setting) resolves to sandbox:

| Variable | Service | `production` | `staging` | otherwise |
| --- | --- | --- | --- | --- |
| `TWO_API_BASE_URL` | checkout API | `api.two.inc` | `api.staging.two.inc` | `api.sandbox.two.inc` |
| `TWO_PORTAL_BASE_URL` | merchant portal | `portal.two.inc` | `portal.staging.two.inc` | `portal.sandbox.two.inc` |
| `TWO_CHECKOUT_BASE_URL` | hosted checkout-page app (sole-trader signup) | `checkout.two.inc` | `checkout.staging.two.inc` | `checkout.sandbox.two.inc` |

(The buyer portal login host has no override; it always follows the configured
environment.)

`make install` and `make run` print the resolved API / portal / checkout-page
hosts (honouring the overrides above and the `_PS_MODE_DEV_` gate) in their
status block, so you can see at a glance which real hosts your local instance
will actually talk to without having to run `dev/probe-hosts.php` yourself.

**Mind the two "checkouts".** `TWO_API_BASE_URL` is the one behind
`getTwoCheckoutHostUrl()` and the `checkout_host` value handed to the browser —
that is the **API**. `TWO_CHECKOUT_BASE_URL` is the hosted checkout-page **app**
that serves the sole-trader signup page. Setting one does not move the other.

All three are **only honoured when the shop is in dev mode** (`_PS_MODE_DEV_`,
which the local `docker-compose.yml` sets via `PS_DEV_MODE=1`). A shop that is
not in dev mode ignores them even when they are set in its process environment,
so they never become a way to repoint a live checkout. There is no admin UI for
any of them.

They resolve **independently** — setting one leaves the other two on their
defaults. That is the point: the common case is a staging API plus a
checkout-page you are editing yourself.

Note that the container reads these at **creation** time. `make run` only starts
the containers you already have, so it keeps whatever values they were created
with, and changing a value means creating the container again. `make install` is
the supported way to do that — be aware that it runs `clean` first, so it drops
the database volume and rebuilds the shop (and it is also what re-runs the
module install, the country/carrier seeding and the storefront proxy patch).

**Everything on your machine.** The API and portal hosts are fetched
*server-side*, from inside the container, which reaches your machine through
`host.docker.internal` (mapped in `docker-compose.yml`). The signup page is
different: the module only hands its URL to the browser, which opens it in a
popup and then origin-checks the `postMessage` that comes back — so
`TWO_CHECKOUT_BASE_URL` has to be a host the **browser** can resolve, i.e.
`localhost`, not `host.docker.internal`:

```bash
make install \
  TWO_API_BASE_URL=http://host.docker.internal:8080 \
  TWO_PORTAL_BASE_URL=http://host.docker.internal:8081 \
  TWO_CHECKOUT_BASE_URL=http://localhost:3000
```

**Remote shop, checkout-page on your laptop (FRP tunnel).** A remote instance
(e.g. GKE-hosted) is not on your machine, and neither `host.docker.internal` nor
`localhost` means anything useful to a browser pointed at it. Expose your local
checkout-page dev server through an FRP tunnel instead — start it with the
checkout-page project's own tunnel tooling, which reports the hostname it came up
on — and point `TWO_CHECKOUT_BASE_URL` at that hostname:

```bash
make install TWO_CHECKOUT_BASE_URL=https://checkout-<you>.frp.staging.two.inc
```

(Use whatever hostname your tunnel actually reports — the form above is
illustrative. `frp.beta.two.inc` is this repo's own storefront tunnel's server
address, a different FRP environment from checkout-page's.)

For a shop you did not boot with `make`, set the same variable in that
instance's own environment (however that deployment injects env vars) — the gate
and the resolution are identical; `make` only exports it for the local
containers. It still has to be present in the container's environment when the
container is created.

## API Integration

### Endpoints Used
- `/v1/merchant/verify_api_key` - API key validation
- `/v1/order_intent` - Order Intent check
- `/v1/order` - Order creation
- `/v1/order/{id}` - Order updates, refunds
- `/v1/tax_codes/{country}` - The tax codes offered in the 0% line mapping
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
- The optional buyer reference fields the buyer filled in at the payment step
  (see "Optional buyer reference fields" above)

## Stable extension contract: order postprocessing

A shop is not always the merchant's accounting source of record. When the order the
shop recorded is not the order the merchant wants invoiced (a charge the shop left
untaxed that the business books as VAT-inclusive, or a cost the shop adds to the cart
total outside any carrier, say), the merchant fixes it up in their own module on this
hook, before the order reaches Two's API. The module fires the hook consistently and
sends the payload as the merchant's handler returns it. A handler that changes gross,
net or tax is not held to what the shop worked out: it owns that, and Two's API
validates whatever arrives.

### The hook

| | |
| --- | --- |
| Name | `actionTwoOrderPostprocessing` |
| Call | The module calls each subscriber's `hookActionTwoOrderPostprocessing($params)` itself, with `$params['payload']` bound by reference and `$params['context']`, rather than through `Hook::exec()`, which on PrestaShop 8 and 9 discards a subscriber's exception outside debug mode. Which modules run is core's rule: those registered on the hook for the shop, active, in position order, and only native ones under "Disable non PrestaShop modules". Controller exceptions and employee permissions are not applied, so the payload never depends on the page or the employee that triggered the request |
| Subscribe | `$this->registerHook('actionTwoOrderPostprocessing')` in your module's `install()`, and a `hookActionTwoOrderPostprocessing($params)` method |
| Return | none: edit `$params['payload']` in place. It is a reference, the same idiom as core's `actionPresentCart` |
| Order | subscribers run in hook-position order (Design > Positions), each seeing the previous one's edits |
| Supported | PrestaShop 1.7.6, 8 and 9 |

`payload` (array) is the complete request body exactly as the module would send it:
2-decimal amount strings, `line_items`, `tax_subtotals` (when the "Validate tax
subtotals" setting sends them), the order-level `net_amount` / `tax_amount` /
`gross_amount`, buyer, addresses and everything else. A request that has no body
passes an empty array. Any part of it may be changed, including gross amounts, lines,
totals and fields the module does not itself send.

`context` (array):

| Key | Type | Meaning |
| --- | --- | --- |
| `request_type` | string | `order_intent`, `order_create`, `order_update`, `order_confirm`, `capture`, `refund` or `cancel` |
| `trigger` | string | What caused the request, for diagnosis: `precheck`, `strict_intent`, `checkout`, `snapshot_hash`, `admin_edit`, `tracking_number`, `merchant_order_id`, `confirmation`, `payment_return`, `status_change`, `credit_slip`, `refund_remainder`, `buyer_cancel`, and others |
| `endpoint` | string | The API path the request goes to, e.g. `/v1/order/{id}/refund` with the real id |
| `cart` | `Cart` or null | The cart the order was or will be placed from |
| `order` | `Order` or null | The PrestaShop order; null before it exists |
| `shipping_tax_rate` | float or null | The rate the carrier's tax rules group applies at the cart's tax address, whether or not the shipping line was actually taxed. `0.21` means 21%. `0.0` for a "No tax" group, null with no carrier or no such group |
| `fallback_shipping_tax_rate` | float or null | The rate of the module's Default shipping tax code, null when it is not set |
| `contract_version` | int | `1` |
| `order_lines` | array or null | Two's lines for the order, not PrestaShop's `order_detail` rows (those are on `order`): the order's line items as Two holds them, with Two's line ids. They are the lines of the last create or update Two accepted, after the subscribers' edits, as Two's `GET /v1/order/{id}` returns them. Each carries its `id`, `type`, `gross_amount`, `net_amount`, `tax_amount`, `tax_rate`, `tax_code` and the other line fields. Given on `refund` and `order_update`. Null on the other request types, when the module could not read the order from Two, and on the merchant order id sync of a repeated confirmation callback, which reads no order. Read-only: changing it changes nothing |
| `order_refunds` | array or null | Two's refunds for the order: every refund Two already holds for it, from the same `GET /v1/order/{id}` response as `order_lines`, verbatim. Each carries its `id`, its `total_amount` (Two records refunds as negative amounts) and its `line_items`, and each refund line names the order line it credited in `prototype_id`, with the amounts refunded on it, `gross_amount` among them. Given on `refund` and `order_update`, on the same requests and with the same null rules as `order_lines`. On a refund it holds the earlier refunds, not the one being sent. Read-only: changing it changes nothing |

As on every hook, `$params` also carries core's `cookie`, `cart` and `altern`.
`$params['cart']` is the visitor's cart from `Context`, which on an admin edit, a
status change or a refund is not the order's cart: use `$params['context']['cart']`.

`order_lines` comes from the read of the order from Two that a refund and the merchant
order id sync already make. An admin edit or a tracking number reads the order only when
it changed something, so with a subscriber registered the module reads it before
building the update: a save that changes nothing then makes that one read, and a shop
with no subscriber makes the same calls as without `order_lines`. `order_refunds` comes
from that same read, so it adds no call.

### When it fires

Once per outbound order request, immediately before it is sent:

| `request_type` | When |
| --- | --- |
| `order_intent` | The checkout's order-intent pre-check (`precheck`), both when it is built for the checkout and when the module sends it to Two, which it rebuilds from the cart rather than take from the browser; and the authoritative check at payment submit (`strict_intent`) |
| `order_create` | Order creation at checkout (`checkout`), and the rebuild the confirmation step hashes to detect a cart changed during payment (`snapshot_hash`, not sent) |
| `order_update` | An admin order edit, a tracking number, the merchant order id sync after confirmation, and the rebuild that splits a Refunded remainder by tax rate after credit slips (`refund_remainder`, not sent). An admin edit or tracking number Two would refuse is built, so the hook runs, but not sent |
| `order_confirm` | The buyer's return from verification |
| `capture` | The fulfilment status |
| `refund` | The refunded status (a full refund with no body; after credit slips, what is left, as `{amount, currency, line_items, tax_subtotals}`) and a credit slip (a partial refund, `{amount, currency, line_items, tax_subtotals}`). Each line is `{id, quantity, unit_price, discount_amount, net_amount, tax_amount, gross_amount}`, where `id` is the Two order line it refunds; `line_items` is absent when the refund could not be matched to the order's lines |
| `cancel` | The cancelled status, a buyer cancel, and the module's own clean-up cancels |

The hook also runs on every order-intent pre-check during checkout, so keep
subscribers cheap.

### The module's checks

The module checks every order payload it builds against the shop.

**Shop-match checks** ask whether the payload matches what PrestaShop worked out:

- each line's declared tax rate against the amounts PrestaShop stored: products, gift
  wrapping, shipping at a populated Default shipping tax code, and on an order update
  the placed gift wrapping;
- the lines against the cart's totals (on an update, the placed order's);
- the buyer fee line against the cart's fee line;
- on a credit slip, the refunded shipping against the Default shipping tax code the
  order was placed at.

**Whether the payload adds up by itself is Two's API's to judge.** The module does not
check a payload's own arithmetic: each line's gross = net + tax, its tax at its declared
rate and its net = quantity x unit price - discount, that the order has a line, or that
the tax subtotals and the order's `net_amount`, `tax_amount` and `gross_amount` agree
with the lines. Two's API validates the order data it receives, and a check in the module
can only be relaxed or corrected when every merchant upgrades, so the module sends such a
payload as it is and Two's response decides.

**The default handler.** The module registers its own handler on the hook, and that
handler runs the shop-match checks. It runs only when no merchant handler is
registered: no other module on the hook that the module would call (registered for the
shop, active, and allowed by "Disable non PrestaShop modules"). With none, each
shop-match check has the outcome it always had: passed, or refused with the same
refusal. The one difference is that the comparison with the cart's totals now also
judges lines whose gross is not their net + tax, which it used to leave to a check of
the payload's own arithmetic that the module no longer makes. Where more than one check
or build failure applies, which one is reported can differ (see "Order of refusals"
below). The module runs its handler itself, after the hook, so its position under
Design > Positions does not matter.

**A merchant handler owns shop-match correctness.** With one registered, the default
handler stands down: the module logs, once per request, that the shop-match checks are
delegated, naming the handler. What the handler declares against the shop (a changed
gross, an added line, a re-split) is its decision, and the module does not compare it
with the cart. An enabled handler stands the default handler down even when it changes
nothing.

**Nothing else is checked after the hook.** With a merchant handler registered, the
payload it returns is sent as it is, even one that does not add up, or a line the module
built whose declared rate its amounts contradict, which the handler left unchanged. Two's
API judges it.

**Opting back in.** A merchant handler can run the module's shop-match checks itself on
the payload it returns, once it has made its edits. Here it adds a cost outside any
carrier at 21%, a split the shop does not have, so it owns the comparison with the cart's
totals and asks only for the checks on single lines:

```php
public function hookActionTwoOrderPostprocessing($params)
{
    $params['payload'] = self::addOutsideCarrierLine($params['payload'], $params['context'], 0.21);
    // The module's checks on the lines this handler left as built.
    Module::getInstanceByName('twopayment')->runTwoShopMatchChecks($params['payload'], Twopayment::SHOP_MATCH_LINES);
}
```

`runTwoShopMatchChecks($payload, $scope)` takes `Twopayment::SHOP_MATCH_ALL`, the
default, for every shop-match check, or `Twopayment::SHOP_MATCH_LINES` for the checks on
single lines only; any other value throws.

A check on a single line (a declared rate, a credit slip's refunded shipping, the buyer
fee line against the cart's) applies while the payload carries that line as the module
built it, so a line the handler changed or removed is not held to the shop. The check on
the whole order (the lines against the cart's or placed order's totals) compares the
payload passed in, gross, net and tax alike, and runs only with `SHOP_MATCH_ALL`: called
before the edits above, it refuses the order the edits were there to complete, and after
edits that declare a tax split the shop does not have (the same cost at 21%, say), it
refuses that split. A handler that declares its own split uses `SHOP_MATCH_LINES`.
Its comparison leaves out the module's buyer fee line, and the cart's,
only while that line is unchanged, so a handler that changes the fee line owns the
cart-total check as well. The helper throws the refusal the default handler
would have, and a handler that lets it through has the request refused exactly as the
default handler refuses it: same exception, message and log. Outside the hook, or on a
request with no shop-match checks, it does nothing. It is opt-in and part of this
contract.

Not checks, and still refused before the hook: a payload the module cannot build at all,
such as an empty cart, an invalid address, a negative discount, a discount that matches
no cart rule, a fee that cannot be replayed, an unreadable tax code mapping, or a split of
shipping or wrapping that cannot reach the shop's amounts, which includes shipping spread
over several rates of a populated Default shipping tax code.

**The buyer fee.** The cart's buyer fee line is priced on the module's own lines, before
the hook. With no merchant handler, a line that fails a declared-rate check stops that
pricing: the cart keeps the fee line it had, and that line's check refuses the order.
With a merchant handler the checks on single lines are the handler's, so they do not stop
the pricing: the cart carries the fee line the payload sends, and a later update replays
that fee from the placed order. A subscriber that adds a line or changes gross does not
change that price (see [The buyer fee is priced before the hook](#the-buyer-fee-is-priced-before-the-hook)).

**Order of refusals.** With no merchant handler the shop-match checks keep their order
among themselves, but they now run after the payload is built. A build failure that used to
come after a check (a negative discount after a product's declared rate, an invalid
address or an unreadable tax code mapping after any check) is now the refusal reported
when both apply.

### What the module does with the result

The payload goes out as the subscribers return it, once the shop-match checks above
pass where they run.

Two's API validates what arrives, and its error message is written to the module log
and, for an order update, to the order's private messages. With a subscriber that
changes amounts, the invoice Two issues can differ from what the shop charged: that is
the merchant's decision, and the merchant owns what their code declares.

A partial refund's line amounts must sum to its `amount`: a subscriber that changes the
amount should change the lines to match, or remove `line_items`, or Two refuses the
refund and the merchant is told it was not sent.

A refund Two accepts is recorded as the subscribers returned it, so the remainder sent
when the order is later marked Refunded is what Two still holds. That remainder is split
by tax rate against the order rebuilt through the hook (`order_update`,
`refund_remainder`, not sent); a subscriber that throws on that rebuild stops the
remainder, and the order's note says the hook stopped it.

A subscriber that throws, or leaves something that is not an array or cannot be
JSON-encoded, has a bug rather than a declaration. That request is not sent: the
module log gets `TWO_ORDER_POSTPROCESSING_HOOK_FAILED` with the request type, the
back office shows a notice on admin actions, and the buyer sees the module's existing
generic refusal. It never falls back to the unedited payload.

With Debug Mode on, the module logs what the subscribers changed, as a list of paths
with their values before and after. Values outside the amounts and lines are shown only
as `redacted`, since they can carry buyer data.

The module never recomputes totals or subtotals after the hook, since that would
overwrite a subscriber's edits. A subscriber that changes a line also updates the
totals and `tax_subtotals` it affects. The module offers a helper for exactly that:

```php
$payload = Module::getInstanceByName('twopayment')->recomputeTwoOrderTotals($payload);
```

It rebuilds the order `net_amount` / `tax_amount` / `gross_amount`, and `tax_subtotals`
when the payload carries them, from `line_items` with the module's own arithmetic, and
changes nothing else. Each total, and each per-rate subtotal, is the sum of its lines
plus any residual it carried before the hook; the module's own payloads carry none, so
it is the sum of the lines. Its result replaces whatever the payload held, hand edits
included, and it never writes `-0.00`. It is opt-in and part of this contract.

### The buyer fee is priced before the hook

The module's fee calculations operate on the order as the module built it, before any
subscriber runs. On order intent and create, from the cart, the buyer fee is quoted on
the gross of the module's own lines; on an order update, it is replayed from what
PrestaShop recorded on the order (how the checks treat that pricing is under "The buyer
fee" in [The module's checks](#the-modules-checks)). The module does not re-price the
fee on the payload a subscriber returns, so a subscriber that adds a line or changes
gross, net or tax does not change the fee. The shop's own fee row (the cart line, and
the order line once placed) stays at the module's price, and every update replays it,
so a subscriber that changes the fee line re-applies that change on every request.

Any fee consequence of the amounts a subscriber declares is the merchant's, and their
subscriber handles it. With a percentage fee, for example, no fee is charged on a line
the subscriber adds; a merchant who wants it charged changes the fee line in their
subscriber, together with the totals it affects.

### Requirements on a subscriber

- **Deterministic.** It must be a pure function of the payload and the cart. The
  confirmation step rebuilds the order and compares a hash of it with the one taken at
  payment, over the post-hook payload; a subscriber whose output varies refuses
  confirmation as a tampered cart. That includes `trigger`: the payment build
  (`checkout`) and the confirmation rebuild (`snapshot_hash`) must give the same
  payload, so never let the edit depend on it.
- **Cheap.** It runs on every order-intent check.
- **Present.** A disabled subscriber module, or PrestaShop's "Disable non PrestaShop
  modules" switch, means no subscriber: orders then go out as the shop recorded them,
  and the default handler's shop-match checks apply again. The module cannot tell "no
  subscriber" from "subscriber switched off".

### Examples

Re-split shipping the shop recorded untaxed on a "No tax" carrier, at the rate the
merchant's books apply to it, and keep the totals consistent, on the order and on its
refunds:

```php
public function hookActionTwoOrderPostprocessing($params)
{
    // The rate the merchant books shipping at: the shop's carrier says "No tax".
    $rate = 0.21;
    if (!empty($params['payload']['line_items'])) {
        $params['payload'] = self::resplitShipping($params['payload'], $rate);
    } elseif (!empty($params['payload']['tax_subtotals'])) {
        // A refund carries no lines. Only shipping is untaxed in this shop, so its 0% share is shipping.
        $params['payload'] = self::resplitUntaxedRefund($params['payload'], $rate);
    }
}

public static function resplitShipping(array $payload, $rate)
{
    foreach ($payload['line_items'] as &$line) {
        if ($line['type'] !== 'SHIPPING_FEE' || (float) $line['tax_amount'] != 0.0) {
            continue;
        }
        $gross = (float) $line['gross_amount'];
        $net = round($gross / (1 + $rate), 2);
        $line['net_amount'] = number_format($net, 2, '.', '');
        $line['tax_amount'] = number_format($gross - $net, 2, '.', '');
        $line['unit_price'] = $line['net_amount'];
        $line['tax_rate'] = (string) $rate;
        $line['tax_class_name'] = 'VAT ' . number_format($rate * 100, 2) . '%';
    }
    unset($line);

    return Module::getInstanceByName('twopayment')->recomputeTwoOrderTotals($payload);
}

public static function resplitUntaxedRefund(array $payload, $rate)
{
    $untaxed = 0.0;
    foreach ($payload['tax_subtotals'] as $i => $subtotal) {
        if ((float) $subtotal['tax_rate'] == 0.0 && (float) $subtotal['tax_amount'] == 0.0) {
            $untaxed += (float) $subtotal['taxable_amount'];
            unset($payload['tax_subtotals'][$i]);
        }
    }
    if ($untaxed == 0.0) {
        return $payload;
    }
    $net = round($untaxed / (1 + $rate), 2);
    $tax = round($untaxed - $net, 2);
    foreach ($payload['tax_subtotals'] as &$subtotal) {
        if (abs((float) $subtotal['tax_rate'] - $rate) < 0.000001) {
            $subtotal['taxable_amount'] = number_format((float) $subtotal['taxable_amount'] + $net, 2, '.', '');
            $subtotal['tax_amount'] = number_format((float) $subtotal['tax_amount'] + $tax, 2, '.', '');
            $net = null;
        }
    }
    unset($subtotal);
    if ($net !== null) {
        $payload['tax_subtotals'][] = array(
            'taxable_amount' => number_format($net, 2, '.', ''),
            'tax_amount' => number_format($tax, 2, '.', ''),
            'tax_rate' => number_format($rate, 6, '.', ''),
        );
    }
    $payload['tax_subtotals'] = array_values($payload['tax_subtotals']);

    return $payload;
}
```

On a 100.00 product at 21% with 29.00 of untaxed shipping, the shipping line becomes
23.97 net + 5.03 tax = 29.00, and the order 123.97 + 26.03 = 150.00: the same gross,
split the way the merchant books it. Without the `recomputeTwoOrderTotals()` call the
order goes to Two with tax subtotals and totals that no longer match the lines, for Two's
API to judge; the Debug Mode diff then names only the shipping line's fields, which
points straight at the missing update.

A PrestaShop refund carries no lines: a full refund has no body, and a credit slip, or
what is left after slips, sends `{amount, currency, tax_subtotals}`. Its `tax_subtotals`
split the refund by the rates the order was placed at, shipping following the Default
shipping tax code table, so a subscriber that re-splits shipping on the order must
re-split the refund's `tax_subtotals` the same way, as `resplitUntaxedRefund()` does: a
credit slip refunding the 29.00 of shipping goes out as 23.97 + 5.03 at 21%, the amount
unchanged. It moves every untaxed share, so it suits a shop where only shipping is
untaxed. A shop with untaxed products can tell the shares apart from
`$params['context']['order_lines']`: each refund line's `id` is the Two order line it
refunds, and that line's `type` there says whether it is shipping or a product. The same
lines tell a subscriber that added its own line at create which share of a refund is
that line's.

What is left to refund on one of Two's order lines is that line's amount less the sum of
what Two's earlier refunds credited it. `order_lines` gives the line's amount and
`order_refunds` the earlier refunds, each refund line naming the order line it credited
in `prototype_id`:

```php
public static function leftToRefund(array $context, $lineId)
{
    if (!is_array($context['order_lines']) || !is_array($context['order_refunds'])) {
        return null;
    }
    $left = null;
    foreach ($context['order_lines'] as $line) {
        if ((string) $line['id'] === (string) $lineId) {
            $left = (float) $line['gross_amount'];
        }
    }
    if ($left === null) {
        return null;
    }
    foreach ($context['order_refunds'] as $refund) {
        foreach (isset($refund['line_items']) ? $refund['line_items'] : array() as $refunded) {
            // Each refund line names the order line it credited in prototype_id; Two records refunds as negatives.
            if (isset($refunded['prototype_id']) && (string) $refunded['prototype_id'] === (string) $lineId) {
                $left -= abs((float) $refunded['gross_amount']);
            }
        }
    }

    return round($left, 2);
}
```

A subscriber that itemises a refund itself keeps each line's share within that. When the
module cannot itemise a refund it logs why, ending "sending the refund without line
items", only if the payload sent after the hook still has no lines.

Add a line for a cost the shop adds to the cart total outside any carrier. The shop's
total carries it and the lines the module built do not, so the default handler would
refuse the order; a handler that adds the line owns that check. On an order update the
module's shipping line is whatever the placed order's total charged beyond its products,
wrapping and discounts, so it carries the cost on top of the carrier's shipping: there the
handler cuts that line back to the carrier's own shipping and adds the same line the
create sent, so create and update carry the same lines. It handles an order with one
shipping line:

```php
public static function addOutsideCarrierLine(array $payload, array $context, $rate)
{
    if ($context['request_type'] === 'order_update') {
        $carrier = round((float) $context['order']->total_shipping_tax_incl, 2);
        $gross = 0.0;
        foreach ($payload['line_items'] as $i => $line) {
            if ($line['type'] !== 'SHIPPING_FEE') {
                continue;
            }
            $gross = round((float) $line['gross_amount'] - $carrier, 2);
            if ($gross > 0 && $carrier > 0) {
                $payload['line_items'][$i]['gross_amount'] = number_format($carrier, 2, '.', '');
                $payload['line_items'][$i]['net_amount'] = number_format((float) $line['net_amount'] - $gross, 2, '.', '');
                $payload['line_items'][$i]['unit_price'] = $payload['line_items'][$i]['net_amount'];
            } elseif ($gross > 0) {
                unset($payload['line_items'][$i]);
            }
            break;
        }
        $payload['line_items'] = array_values($payload['line_items']);
    } elseif (in_array($context['request_type'], array('order_intent', 'order_create'), true)) {
        $gross = round((float) $context['cart']->getOrderTotal(true, Cart::BOTH) - (float) $payload['gross_amount'], 2);
    } else {
        return $payload;
    }
    if ($gross <= 0) {
        return $payload;
    }
    $net = round($gross / (1 + $rate), 2);
    $payload['line_items'][] = array(
        'name' => 'Delivery', 'description' => '', 'gross_amount' => number_format($gross, 2, '.', ''),
        'net_amount' => number_format($net, 2, '.', ''), 'discount_amount' => '0.00',
        'tax_amount' => number_format($gross - $net, 2, '.', ''), 'tax_class_name' => 'VAT ' . number_format($rate * 100, 2) . '%',
        'tax_rate' => (string) $rate, 'unit_price' => number_format($net, 2, '.', ''), 'quantity' => 1, 'quantity_unit' => 'pcs',
        'image_url' => '', 'product_page_url' => '', 'type' => 'SHIPPING_FEE',
    );

    return Module::getInstanceByName('twopayment')->recomputeTwoOrderTotals($payload);
}
```

A 29.00 cost becomes a 23.97 + 5.03 shipping line at 21%, on the create and on later
updates alike, beside the carrier's shipping as the module built it, and the order gross
becomes the cart's. The `recomputeTwoOrderTotals()` call is what keeps the order totals and
`tax_subtotals` consistent with the new line; without it they no longer match the lines
when the order reaches Two's API.

A working subscriber, exercised on every request type in CI, is
`tests/integration/fixtures/twoorderpostprocessingtest`. Its `resplitShipping()`,
`resplitUntaxedRefund()` and `addOutsideCarrierLine()` are the methods above, verbatim,
and the offline suite fails if they drift apart.

### Versioning

- The hook is permanent. It is never removed or renamed, it fires consistently on the
  same events, and its version 1 context keys and `request_type` values keep their
  meaning.
- Allowed without a version change: new context keys, and new `request_type` or
  `trigger` values.
- Never: removing or renaming a context key, changing units (rates stay decimal
  fractions), or firing on fewer request types.
- A genuinely incompatible version 2 would be a new hook name, with version 1 still
  firing beside it. `CHANGELOG.md` records any change to this contract, and the CI
  fixture pins version 1.

## Troubleshooting

### Company Search Not Working
- **Symptom**: No results when typing company name
- **Solutions**:
  - Ensure at least 3 characters typed (below the threshold the dropdown says so)
  - Check browser console for API errors
  - Verify network calls to `/companies/v2/company`
  - Check API key is valid
  - Verify country selection (if applicable)

### Buyer's Country Preselected Wrong at the Address Step
- **Symptom**: the invoice address form opens with a country the buyer is not in — and, where
  that country has states, a required state dropdown they cannot satisfy
- **Cause**: PrestaShop itself preselects the country from the browser's `Accept-Language`
  header, ahead of the shop default, when **International → Localization → Detect country from
  browser language** is enabled. It is enabled by default on a fresh install. The module writes
  nothing to the country field
- **Solutions**:
  - Set **Detect country from browser language** to **No** if you want the shop default to win
  - Leave it enabled if you do want the browser's language to choose, and accept that buyers
    whose browser language points elsewhere start on that country

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
  - Verify company data persisted (`twopayment_cart_record` row for the cart, and hidden `companyid` field)
  - Ensure Order Intent was approved (check cookie `two_order_intent_approved`)
  - Verify API key is correct for environment
  - Check Two API status

### Invoice Upload Failing
- **Symptom**: Invoices not uploading to Two
- **Solutions**:
  - Verify the merchant record has `invoice_distributed_by_merchant` set (contact Two support);
    the API returns 403 for upload attempts when it is false
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

### Two Missing from Checkout Entirely
- **Symptom**: The Two payment option (and the company search) do not appear at all, on a shop where they used to
- **Solutions**:
  - Open the module configuration: a stored API key that cannot currently be verified is reported there, with its category and HTTP status. Two is withheld from checkout for as long as that notice shows
  - `invalid_key` means Two rejected the key - re-copy it from the Two portal for the environment selected above it
  - `service_error` means Two answered with a 5xx: nothing to fix on the shop, check Two's status and retry
  - `unreachable` means this shop could not reach the Two API at all - check outbound network, DNS and firewall rules. The key itself has not been judged
  - Search the PrestaShop logs for `API key verification status` - the withholding is always logged with its category
  - Also check the plainer causes: the module disabled, no payment terms enabled, cart below the minimum order value, or an unsupported cart currency (each of which logs its own line)
  - If it is missing only for buyers in certain countries, the merchant record's supported buyer countries do not include theirs. If it is missing for every buyer, that same field may be present on the record with no country in it, or with content the module could not read - both permit nothing. Search the logs for `supported buyer country`: the line names the merchant, the buyer country that was refused and which of the three refused it (`allowlist`, `empty`, `malformed`). The list is set by Two, not in the module configuration; contact Two support to change it. A merchant record that does not carry the field at all is unrestricted

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

### Tax Rate Issues / Checkout Declined on Tax
- **Symptom**: checkout is declined and the log shows "Declared tax rate does not reconcile
  with applied amounts"
- **Cause**: the tax-rules group the merchant configured for that line resolves to a
  different rate than the tax PrestaShop actually applied to it. The module relays the
  declared rate and refuses to invent one — see
  [.ai/vat-rate-sourcing.md](.ai/vat-rate-sourcing.md).
- **Solutions**:
  - Read the log entry: it names the line, the declared rate, the net, the applied tax and
    the tax expected at the declared rate
  - Check that line's tax-rules group, including any address-specific `TaxRule` (state /
    postcode scoped rules are a common source of divergence)
  - Verify products, the carrier, ecotax and gift wrapping all have the intended tax rules
    group assigned
  - Contact Two support with the log entry if the configuration looks correct

### Discrepancy snapshot
When an order is refused by a tax or totals gate (order lines not reconciling with the
cart total, a declared tax rate contradicting the applied amounts, shipping with no declared tax rate, or any other refusal while
pricing the cart), the module writes one JSON record for that cart to the PrestaShop
log (object type `TwoDiscrepancySnapshot`, object id = the cart id). With Debug Mode on
it also writes one for every cart that passes, as a baseline to compare against.
An update to a placed order is priced from that order, not its cart, so it writes no snapshot.

- **Where**: Module Configuration → Diagnostics → "View last 100 error log records" lists
  the snapshots with a **Download JSON** link each (employee login and token required).
- **What it holds**: `Cart::getOrderTotal` with and without tax for every total type, and
  `residual` = BOTH − (PRODUCTS + SHIPPING + WRAPPING − DISCOUNTS); each product line's
  amounts, declared and implied rate and `delta` = total_wt − total × (1 + declared rate);
  the carrier, delivery options and package shipping cost; which core classes are
  overridden and which modules sit on price and shipping hooks; cart rules, gift wrapping
  and the tax and rounding settings; the failed gate and the lines that would have been
  sent. Of the buyer's addresses it keeps the country, the state id and whether a VAT
  number is present, nothing else. The sent lines' names can carry merchant-authored text
  (product, cart rule and carrier names). A section that failed to read holds only
  `{error, code}`: the exception class and code, never its message.
- **Size**: products, cart rules, delivery options, sent lines and each hook's and class's
  list keep their first 40 rows; `truncated` counts the rows cut from each, including a
  whole section shed to fit the size limit. The hook's `diff` keeps its first 40 entries
  and gives way, entry by entry, before any section is shed.
- **Cost**: once one `Cart` pricing read throws, the remaining ones are recorded as
  `{error: "skipped"}` rather than re-run, since core does not cache a failed price.
  `products` is read first, so a carrier that throws never skips it.
- **Log growth**: one row per refused order, and with Debug Mode on two or three per order
  (each pricing pass leaves a baseline), so switch Debug Mode off once done.
- **Reading it**: the record's `shape` field applies this decision tree, first match wins:

  | Shape | Snapshot values | Cart shape |
  | --- | --- | --- |
  | C | `residual` ≠ 0 and `ONLY_SHIPPING` = 0 | a cost is added to the cart total outside the shipping total, so no tax rule covers it |
  | A | `ONLY_SHIPPING` > 0, incl = excl, every carrier id is 0 | shipping priced without a carrier and without tax |
  | B | `ONLY_SHIPPING` incl > excl, every carrier id is 0 | shipping priced without a carrier, with tax nothing declares |
  | other | anything else, including any input that failed to read | read the gate numbers and product lines directly |

  A and B come only from PrestaShop 1.7's `'0,'` option key: on 8 and 9 a package with no carrier gets no delivery option, so `priced_option` is `[]` and the shape is other.

  "Every carrier id is 0" means every carrier id in `priced_option`, the delivery option
  core prices shipping from: when the cart's own `delivery_option` is empty or stale, core
  auto-selects one, and a multi-carrier option lists all its carriers. A real carrier whose
  tax rules group is 0 ("No tax") still has a carrier, so it is never A or B. C also
  needs `BOTH` > 0, since stacked vouchers clamp it to 0.

  Product lines carry no shape label: their raw `declared_rate`, `implied_rate` and
  `delta` (which already allows for ecotax taxed under its own group) are read directly,
  beside the overrides and hooks sections.

  The `order_postprocessing` block (schema `v` 2) records the order postprocessing hook
  when it ran for the request: `request_type`, `trigger`, the `subscribers` registered on
  it, whether they `changed` the payload, the `outcome` (`unchanged`, `changed` or
  `refused:<code>`), `cart_reconciliation` and a `diff` of `{path, before, after}` entries
  keyed by JSON pointer (buyer and address fields by path only). A `changed` outcome
  explains figures that differ from the shop's own records, and
  `cart_reconciliation: skipped_payload_changed` explains why no cart comparison ran.
  `sent_line_items` are always the post-hook lines. See "Stable extension contract:
  order postprocessing".

#### Snapshot invariants
- The snapshot never changes, fails or slows a checkout. Every one of its own failures is
  swallowed, including its fallback logging.
- Every refusal of the pricing build writes exactly one snapshot, named after the gate that
  recorded its numbers (also when that exception arrives wrapped) or else after the
  exception class. Nothing is written when nothing
  refused (the Debug Mode baseline aside), nor for a cart with no valid line items.
- No buyer PII leaves the address section. Third-party exception messages are never
  stored, only the class and code.
- A `Cart` pricing read that throws is made once per snapshot, never repeated.
- An unrecorded refusal names its exception class, code and throw site (module-relative
  file and line), never its message.
- The shape is a positive identification only. Any errored, missing or ambiguous input
  yields `other`, never A, B or C.
- The stored JSON survives PrestaShop's log storage on 1.7, 8 and 9 byte-for-byte: `<` and
  `>` are escaped, since core runs `strip_tags()` on every log message.

### Debug Mode
- **When to use**: Only enable when requested by Two support for troubleshooting
- **How to enable**: 
  1. Go to Module Configuration → Other Settings
  2. Toggle "Enable Debug Mode" to Yes
  3. Save settings
  4. Reproduce the issue
  5. Check PrestaShop logs (`var/logs/`); each cart priced while it is on also leaves a
     baseline discrepancy snapshot (see above)
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
