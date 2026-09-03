# Two Payment Plugin for PrestaShop

AI agent operating manual for the `twopayment` module.

## 1) Current Truth

| Item | Value |
| --- | --- |
| Module | `twopayment` |
| PrestaShop support | `1.7.6` to `9.x` (`ps_versions_compliancy` in `twopayment.php`) |
| Core model | Provider-first checkout (Two first, PrestaShop order second) |
| Main file | `twopayment.php` |

Canonical version sources:
- `twopayment.php` (`$this->version`)
- `config.xml` (`<version>`)
- `CHANGELOG.md` top entry

These must stay aligned.

## 2) Product Goal

Reliable B2B invoice checkout via Two, with:
- No phantom local orders when provider-side creation/review fails
- Accurate tax/amount payload parity with PrestaShop totals
- Safe retry behavior (idempotency)
- Clear admin observability of Two order lifecycle

## 3) Non-Negotiable Invariants

1. Never create a local PrestaShop order if Two order creation/verification fails.
2. Apply the rejection/rollback rule for all countries, not Spain-only logic.
3. Keep provider-first flow intact: Two acceptance/verification gates local order creation.
4. Keep idempotency on provider order creation paths.
5. Do not weaken server-side validation gates even if frontend checks exist.
6. Preserve tax math integrity (`tax_amount = net_amount * tax_rate`, within rounding constraints).
7. Do not expose secrets or disable SSL verification by default.
8. If user-facing text changes, update all required i18n surfaces (PHP, JS i18n map, translations).

## 4) Core Flow (High Level)

### Checkout and Order Creation
1. Buyer enters/selects company details.
2. Order intent check runs (frontend + server-side persistence/validation).
3. Two order is created first.
4. Only after provider-side success, local PrestaShop order is finalized.

### Retry/Idempotency
- Repeated submit/callback paths must not duplicate provider orders.
- Attempt tracking table + idempotency headers are part of the safety model.

### Admin Order View
- Show stable stored identifiers and state info.
- Where available, refresh/fetch current provider metadata for accuracy.
- Invoice links/actions should only be shown when lifecycle state permits (for example after fulfillment).

## 5) File Ownership Map

### Core Module
- `twopayment.php`
  - Hooks, config fields, API wrappers, payload building, i18n JS dictionary
  - Main place for business rules and invariants

### Front Controllers
- `controllers/front/payment.php`
  - Provider-first payment orchestration and final local order safety
- `controllers/front/orderintent.php`
  - Ajax order intent, company context, guardrails
- `controllers/front/confirmation.php`
  - Post-checkout result handling
- `controllers/front/cancel.php`
  - Cancellation/error paths

### Frontend Checkout Modules
- `views/js/modules/TwoCheckoutManager.js`
  - Payment-option behavior, approval/decline UX, terms UI
- `views/js/modules/TwoOrderIntent.js`
  - Order intent polling and UI messaging
- `views/js/modules/TwoCompanySearch.js`
  - Company discovery and selection
- `views/js/modules/TwoSoleTrader.js`
  - Sole-trader enrolment mechanics and the availability answer
    `TwoCompanySearch.js`'s "I'm a sole trader" row reads (TWO-40 removed the
    old upfront chip UI this module used to render itself). Availability
    resolution does NOT depend on `.two-sole-trader` existing on the page
    (TWO-40 follow-up) - that container, rendered only by `paymentinfo.tpl`,
    exists solely to host enrolment prompt/status/error messaging. The answer
    is adopted server-side on first paint where the container IS present
    (TWO-25326), resolved over `soleTraderAvailability` otherwise, and cached
    both in memory and in localStorage per country (24h TTL, namespaced per
    checkout environment) so a later page load can skip the round trip.
    Enrolment (`startEnrollment()`/`getCurrentBuyer()`) also has to work with
    no container at all - on the address-editor page, where the chip lives
    (`TwoCompanySearch.js`) but `.two-sole-trader` never renders - so a
    no-match buyer lookup there calls `openPopup()` directly instead of the
    payment-step's two-click `showPrompt()`->`openPopup()` (TWO-40 follow-up).
    Tokens are minted, and the 30-minute refresh armed, as soon as an eligible
    billing country resolves rather than on the buyer's first chip click, so
    `window.open()` does not sit behind a mint inside the gesture; that eager
    mint is never acted on, since only a mint a click is waiting on may open a
    popup or start a buyer lookup (TWO-40 follow-up).
    Minting posts the buyer's currently selected country, because the cart has
    no invoice address on that page - the server resolves invoice address ->
    posted country -> delivery address and refuses if none resolves, with the
    registry check still the only authorisation gate (TWO-40 follow-up)
- `views/js/modules/TwoOptionalFields.js`
  - Optional buyer reference fields in the payment tile: mirrors each visible
    input into its hidden twin inside the payment form (the tile is a sibling
    of that form, not a child), and rejects a malformed invoice email on submit

### Admin UI
- `views/templates/hook/displayAdminOrderLeft.tpl`
- `views/templates/hook/displayAdminOrderTabContent.tpl`

### Tests
- `tests/run.php` — self-contained runner (inline order-builder specs plus an explicit
  `require` per `tests/*Spec.php`; a new spec file must be added to that list)
- `tests/e2e/` — Playwright checkout suite
- See `tests/README.md`

### Upgrades
- `upgrade/upgrade-*.php`

## 6) i18n Rules

When adding/changing user-facing strings:
1. Wrap PHP strings with `$this->l(...)`.
2. For JS, expose keys via `Media::addJsDef(... 'i18n' => [...])` in `twopayment.php`.
3. Consume keys in JS; avoid raw English literals for user-facing errors/messages.
4. Add/update every locale in `translations/` — `es.php`, `nl.php`, `no.php`, `sv.php` — with
   natural phrasing, not literal machine output. Dutch uses the informal `je`/`jouw` register.
5. Re-check template `{l s=... mod='twopayment'}` coverage.
6. Edit those files by hand. Do not regenerate them from the back office translation screen:
   it keys module PHP strings by filename, while the runtime looks them up under the module
   name, so a save there produces rows nothing reads. See the i18n section of `AGENTS.md`.

## 7) Tax and Amount Safety

**Read [.ai/vat-rate-sourcing.md](.ai/vat-rate-sourcing.md) before touching any payload
builder.** It is the authoritative account of how rates are sourced and where the payload
fails loud. The one rule that governs everything else: the plugin relays the merchant's
declared tax rate and never derives one from the amounts.

Before changing payload builders:
- Verify line-item formulas and rounding behavior.
- Ensure product-level, shipping, wrapping, ecotax and discount lines reconcile to order totals.
- Validate behavior for mixed rates and tax-exempt contexts.

Always run the order-builder test suite after tax/amount edits.

## 8) Verification Commands (Minimum)

Run from module root:

```bash
make test              # php tests/run.php in the CI container
make test-js           # jest over views/js (jsdom + real jQuery/jQuery UI); host Node 20+
make phpstan           # the static-analysis gate CI runs
make test-integration  # real-engine probes; needs `make carrierless-shop` first
```

Lint any PHP file you touched (`php -l path/to/file.php`).

## 9) Logging and Debugging

- Use `PrestaShopLogger::addLog` with actionable context (`order_id`, `two_order_id`, endpoint/action).
- Never log API keys, tokens, or sensitive customer data.
- Use debug mode only for targeted diagnosis; keep normal logs concise.

## 10) Common Regression Risks

1. Reintroducing local order writes before provider acceptance.
2. Country-specific error branching that bypasses global rejection guardrails.
3. Broken idempotency on retries/timeouts.
4. Silent mismatch between frontend i18n keys and PHP-provided dictionary.
5. Admin view showing invoice actions before provider invoice lifecycle is ready.
6. Version mismatch across `twopayment.php`, `config.xml`, `CHANGELOG.md`.

## 11) Release Hygiene Checklist

Before release/tag:
1. Version synchronized across module files.
2. Upgrade script exists and is referenced where needed.
3. Changelog entry accurate and merged cleanly.
4. Tests green.
5. No temporary debug code/messages.
6. Translation updates included for changed user-facing text.

## 12) Working Style for Agents

- Prefer minimal, targeted diffs over broad rewrites.
- Preserve backward compatibility unless explicitly changing behavior.
- Document any behavior-changing decision in `CHANGELOG.md` and relevant docs.
- If a rule here conflicts with code behavior, update this file in the same change.
- Document only current behaviour here. History belongs in `CHANGELOG.md`; dated
  engineering notes belong in `.ai/decisions.md` / `.ai/learnings.md`.
