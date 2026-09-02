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
| Default shipping tax code | Tax rules group assumed for shipping when the carrier's rate cannot be resolved — see below | Not set |

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

**Who needs this:** shops that price shipping outside PrestaShop's carrier table — third-party carrier modules, click-and-collect, marketplace shipping, or any custom logistics setup that never registers a tax rules group.

PrestaShop declares shipping VAT per carrier, in `carrier_tax_rules_group_shop`, and nowhere else — there is no shop-level shipping tax rules group. The module relays that declaration; it never derives a VAT rate from the amounts. A shop whose shipping is priced outside the carrier table leaves `id_carrier = 0`, PrestaShop then hands the module an empty delivery-option list, and with no carrier there is no declared rate to relay — so the order is refused rather than shipped with a guessed rate.

The **Default shipping tax code** setting, in **Module Configuration → Order management**, lets such a merchant make that declaration on the module instead. It is assumed **for shipping only, and only when the carrier's tax rate cannot be resolved for the order**. When a carrier does declare a tax rules group, the carrier always wins.

Resolution order:

1. The tax rules group declared by the carrier(s) in the cart's selected delivery option
2. **Default shipping tax code**, if set
3. Refuse the order (the pre-existing behaviour), if neither

The setting has **no default value**. An install that never sets it behaves exactly as it did before the setting existed.

Notes:

- Selecting a group that is later deleted is treated as "not set" — the order is refused, not relayed at 0%.
- Every order that actually uses the fallback writes a warning to the shop log naming the group, its id and the resolved rate, e.g. `assuming the configured Default shipping tax code "IVA 21%" (tax_rules_group=12, rate=21%)`. If you never see that line, the fallback is not being used.

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
- Customer types at least 3 characters in the `Company` field; below that the dropdown says `Please enter 3 or more characters` rather than staying shut. The threshold is a single constant in `views/js/modules/TwoCompanySearch.js` and is interpolated into that message, so the number shown always matches the number enforced
- Module searches Two's Company API v2 (frontend call)
- Customer selects a company from search results
- Module stores organization number in hidden `companyid` field
- Address fields, DNI and VAT number auto-fill from Two's data when available, and a re-search overwrites them with the newly selected company's values. Merchant-configurable (Company Lookup -> "Autofill company address", enabled by default); with it off, the company search still records the company name and organisation number but writes nothing into the address step
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
make format     # php-cs-fixer (PSR-12)
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

## Troubleshooting

### Company Search Not Working
- **Symptom**: No results when typing company name
- **Solutions**:
  - Ensure at least 3 characters typed (below the threshold the dropdown says so)
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

### Debug Mode
- **When to use**: Only enable when requested by Two support for troubleshooting
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
