# AGENTS.md — Two Payment Module (PrestaShop)

Project-specific instructions for AI coding agents working in this repository.

## Scope

These rules apply to all files under this module directory.

## Mission

Build and maintain a robust B2B payment module where Two provider behavior and PrestaShop order state stay consistent, auditable, and safe.

## Hard Constraints

1. Never create a local PrestaShop order if Two rejects/fails order creation.
2. Apply rejection/rollback protections globally (not by country-specific exception).
3. Preserve provider-first flow and retry idempotency.
4. Do not weaken server-side validation in favor of frontend checks.
5. Keep tax/amount formulas consistent with existing test expectations. Relay the
   merchant's declared tax rate; never derive one from the amounts — see
   `.ai/vat-rate-sourcing.md`.
6. Never expose secrets in logs or code.
7. Never default to insecure transport behavior.

## Required Verification Before Claiming Done

Run from module root — these are the same gates CI runs:

```bash
make test      # php tests/run.php
make test-js   # jest over views/js (host Node 20+, not containerised)
make phpstan   # static analysis
```

If you touched shipping-tax resolution, also run the real-engine probes:
`make carrierless-shop && make test-integration`; `make carrierless-matrix` tabulates carrier-less modes × tax-code configs on a throwaway shop.
CI runs them on PrestaShop 8 and 9.

Lint each PHP file you edited:

```bash
php -l path/to/file.php
```

`make help` lists the rest (local stack, version bump).

## i18n Requirements

For every user-facing string change:
- Update PHP/Smarty translation surfaces (`$this->l`, `{l ...}`)
- Update JS i18n dictionary in `twopayment.php` when used by frontend modules
- Update every locale in `translations/`: `es.php`, `nl.php`, `no.php`, `sv.php` — natural
  phrasing, not literal machine output. Dutch uses the informal `je`/`jouw` register.
- Avoid hardcoded English UI fallback where module i18n is available
- Check the result with `dev/i18n-audit.sh`. CI runs it as its own job; a new
  locale also needs adding to `GATED_LOCALES` in `tests/TranslationCatalogueSpec.php`,
  or the audit fails on a catalogue nothing gates.

**Never regenerate a `translations/*.php` file from the PrestaShop back office**
(Translations > Module translations). Its writer derives the key's source segment from the
*filename* for `.php` files as well as templates, but at runtime every `->l()` here reaches
`Module::l()` with no `$specific`, so the source segment is always the module name. Saving
from the back office therefore rewrites the module's own strings to keys nothing looks up.
Edit these files by hand. Norwegian is `no.php` (PrestaShop's `iso_code`), never `nb.php`.

## File Ownership Reference

- `twopayment.php`: hooks, settings, API interactions, payload and i18n map
- `controllers/front/payment.php`: checkout confirmation + order creation safety
- `controllers/front/orderintent.php`: order intent API and gating data
- `views/js/modules/*.js`: checkout UX logic and client validation
- `views/templates/hook/*.tpl`: admin and checkout rendering
- `tests/run.php`: tax/amount/order payload invariants (self-contained runner, no composer deps)

## Task Docs

Read the linked doc before working in its area; each holds rules moved out of this file.

Every "read before" line below applies equally when reviewing a PR that touches that area.

- Read [.ai/admin-settings-and-merchant-record.md](.ai/admin-settings-and-merchant-record.md) before changing:
  - any module setting: its form validator, its getter, the admin configuration form, or any hook that reads a setting;
  - the General save, the save-time API-key check (`verify_api_key`), the stored merchant id (`PS_TWO_MERCHANT_ID`) or short name;
  - `isDefinitiveFailureStatus()` or any of its callers: `hookPaymentOptions()`, the payment POST gate in `controllers/front/payment.php`, and the server-side company-search affordance `isTwoCompanySearchAffordanceWarranted()`;
  - anything else that withholds Two at checkout, including `isTwoSurchargeQuotableForCart()`;
  - buyer surcharge pricing: fee quotes, `API_TIMEOUT_SURCHARGE_PRICING`, the quote caches (request-scoped and cookie), the surcharge fee line and its order-create parity check, the admin fee-rate preview, or the fee shown on the term chips;
  - payment-term availability: `getAvailablePaymentTerms()`, `getConfigurableTermSet()`, `getDefaultPaymentTerm()`, `PAYMENT_TERMS_OPTIONS`, `DEFAULT_PAYMENT_TERM_DAYS`, or the default term published to the checkout JS;
  - the cached merchant record and its refresh: `controllers/front/cron.php`, the Diagnostics button, `MERCHANT_RECORD_STALE_AFTER`.
- Read [.ai/payload-checks-tenders-tax-codes.md](.ai/payload-checks-tenders-tax-codes.md) before changing:
  - the order create, update or refund payload builders, the credit slip payload, or any check on a payload (`buildTwoOrderChecks()`, `$twoDeferredShopMatch`, refusals in the builder);
  - the merchant hooks around the payload (`actionTwoOrderTenders`, the postprocessing hook and its context, including `fallback_shipping_tax_code`);
  - tenders, `prepaid_amount`, `classes/TwoOrderTenders.php`, or how a cart rule is sent;
  - tax codes: `TwoShopTaxCode`, `applyTwoTaxCodes()`, `getTwoTaxRuleForAddress()`, `readTwoTaxCodeMap()`, the tax-code mapping and its admin form, or an upgrade script that migrates it;
  - the buyer VAT number (`getTwoBuyerVatNumber()`, `buyer_vat_number`);
  - anything written to or read from `two_declared_rates`;
  - a new kind of order line (`tagTwoLineTaxKeys()`), the `TaxCodeSpec` goldens, or the tax-code mapping check in `hookPaymentOptions()`.
- Read [.ai/company-search-and-term-chips.md](.ai/company-search-and-term-chips.md) before changing:
  - `views/js/modules/TwoCompanySearch.js`, `views/js/modules/TwoSoleTrader.js`, or the server-side company-search affordance (`isTwoCompanySearchAffordanceWarranted()`) and country coverage list;
  - any focus, popover, popup or tab-stop handling on the checkout, or the company-name field;
  - the payment-term chips in `views/js/modules/TwoCheckoutManager.js` or their templates: markup, roles, keyboard, selection persistence, visible text, `title` / `aria-label`, or their translated strings;
  - and before describing a company-search change as cross-platform.
- Read [.ai/js-testing-traps.md](.ai/js-testing-traps.md) before:
  - adding or changing any JS test, fixture or harness (`tests/js/`, `tests/js/ps-harness.js`, `tests/js/setup-jquery-ui-110.js`, the Jest projects), or any module under `views/js/` (every spec runs on two jQuery UI versions);
  - using a mutation to claim a test adds coverage;
  - verifying browser behaviour such as keyboard focus, tab order or whether a popup opened.
- Read [.ai/custom-request-headers.md](.ai/custom-request-headers.md) before changing the custom request-header table: how a header name or value is validated (including any validating regex), stored or read, or adding a migration or upgrade script for it.
- Read [.ai/upgrade-scripts-and-overrides.md](.ai/upgrade-scripts-and-overrides.md) before:
  - adding or changing an `upgrade/` script, `tests/UpgradeScriptVersionSpec.php`, or the version computation and its checks (`.github/workflows/version-bump.yml`, `.github/scripts/check-upgrade-script-version.sh`);
  - changing or retiring anything under `override/`, `classes/TwoOverrideMigrator.php`, or `.github/scripts/check-override-migration.sh` and its test;
  - changing a `.tpl`, or diagnosing a shop that runs old behaviour or stale templates after an upgrade.

## State Propagation Runs Plugin To Provider Only

There is no inbound path from the provider or the merchant portal into this module, by design. A
portal-side action such as a refund leaves the shop's order status untouched, so that status is not
a reliable indicator of provider-side state. Do not add a one-off bridge for a single status; see
`.ai/decisions.md` (TWO-25706).

## Brand Values Are Install-Wide: There Is No Overlay Mechanism

`brands/two.php` is the whole brand-config seam. `Twopayment::getTwoBrandConfig()` requires that one
path and nothing selects a second file, so every value in it - the payment tile's about-page URL
`about_url` among them - is install-wide, and a brand wanting its own value has to
replace the file. Building per-brand resolution is TWO-24746 and has not been done here; do not
improvise one for a single key.

## A Guard Is Invoked Through `bash`

A script committed mode `100644` and run as `./script.sh` exits 126. On a CI
dashboard that is indistinguishable from a check that ran and failed, so the guard's
own absence reads as its verdict. Invoke anything whose failure mode is "did not
execute" as `bash script.sh`, and have it print what it checked.

## This Is A Public Repository

- No partner or merchant name reaches file contents, a commit body, a branch name or
  a PR title or body. Gate before pushing: a force-push afterwards does not remove a
  commit from GitHub's history.
- In comments, commit messages and PR bodies alike, cite a Linear ticket id and
  nothing else: a section, question or ruling number belonging to an internal review
  document means nothing to a reader outside the company, and neither does a person
  named as the authority for a rule.
- Describe another plugin's behaviour in your own words; never reproduce its source
  text, schema fragments or test identifiers here.
- Name the repository on every pull-request or issue reference:
  `prestashop-plugin PR #211`, `woocommerce-plugin PR #487`. A bare `#211` renders
  as a live link to whatever that number happens to be in this repository. A Linear
  id such as `TWO-25326` is unambiguous on its own and needs no prefix.

## Change Quality Rules

- Keep diffs targeted; avoid unrelated refactors in payment-critical paths.
- Preserve backward compatibility unless change request is explicit.
- Add or update tests for behavior changes in payload, validation, or flow control.
- Update `CHANGELOG.md` for functional changes.

## Release Consistency Rules

**Do not hand-bump the version for a PR into `staging`.** The bump is automated
(`.github/workflows/version-bump.yml`, TWO-25256): the version is computed from
this PR's own conventional-commit subjects and committed onto the PR's branch, so
by review time the tree already declares the version it will ship as. `make bump`
previews that decision and writes nothing. `main` computes nothing at all - it
tags the version already in the tree.

When bumping/releasing versions, keep these in sync:
- `twopayment.php` version
- `config.xml` version
- `CHANGELOG.md`

Read [.ai/upgrade-scripts-and-overrides.md](.ai/upgrade-scripts-and-overrides.md) before adding an
`upgrade/` script or touching anything under `override/`: both fail silently on a shop.

## Common Failure Patterns to Avoid

- Reintroducing local order writes before provider success.
- Losing idempotency on retries/timeouts.
- Country-specific tax/error branching that bypasses global safeguards.
- Admin UI showing invoice actions too early in order lifecycle.
- Updating JS messages without adding corresponding translation keys.
