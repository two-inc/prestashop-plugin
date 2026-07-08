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
5. Keep tax/amount formulas consistent with existing test expectations.
6. Never expose secrets in logs or code.
7. Never default to insecure transport behavior.

## Required Verification Before Claiming Done

Run from module root:

```bash
php -l twopayment.php
php tests/run.php
```

If you edited additional PHP files, lint each one:

```bash
php -l path/to/file.php
```

## i18n Requirements

For every user-facing string change:
- Update PHP/Smarty translation surfaces (`$this->l`, `{l ...}`)
- Update JS i18n dictionary in `twopayment.php` when used by frontend modules
- Update `translations/es.php` with natural Spanish
- Avoid hardcoded English UI fallback where module i18n is available

## File Ownership Reference

- `twopayment.php`: hooks, settings, API interactions, payload and i18n map
- `controllers/front/payment.php`: checkout confirmation + order creation safety
- `controllers/front/orderintent.php`: order intent API and gating data
- `views/js/modules/*.js`: checkout UX logic and client validation
- `views/templates/hook/*.tpl`: admin and checkout rendering
- `tests/run.php`: tax/amount/order payload invariants (self-contained runner, no composer deps)

## Change Quality Rules

- Keep diffs targeted; avoid unrelated refactors in payment-critical paths.
- Preserve backward compatibility unless change request is explicit.
- Add or update tests for behavior changes in payload, validation, or flow control.
- Update `CHANGELOG.md` for functional changes.

## Release Consistency Rules

When bumping/releasing versions, keep these in sync:
- `twopayment.php` version
- `config.xml` version
- `CHANGELOG.md`

## Common Failure Patterns to Avoid

- Reintroducing local order writes before provider success.
- Losing idempotency on retries/timeouts.
- Country-specific tax/error branching that bypasses global safeguards.
- Admin UI showing invoice actions too early in order lifecycle.
- Updating JS messages without adding corresponding translation keys.
