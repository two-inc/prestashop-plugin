---
description: "Order payload shop-match checks, tenders as prepaid_amount, and tax codes on 0% lines"
---

# Payload Checks, Tenders And Tax Codes

Moved verbatim from `AGENTS.md`.

## Payload Checks: Shop-Match Only; Arithmetic Is Two's API's

TWO-26274, TWO-26283. The README section "The module's checks" is the specification.

- **Shop-match** (the payload against PrestaShop's figures: cart or placed totals, stored
  line amounts, the cart's fee line, the Default shipping tax code) belongs to the default
  handler: an item in `buildTwoOrderChecks()` (or the credit slip's), run after the hook
  only when no merchant handler is registered. A check on one line is recorded against that
  line while it is built (`$twoDeferredShopMatch`) and applies only while the payload still
  carries the line unchanged.
- **Never check the payload against itself** (line arithmetic, at least one line, tax
  subtotals or order totals against the lines). Two's API validates order data, and a
  module check cannot be relaxed or fixed without every merchant upgrading, so one stricter
  than the API refuses orders Two would accept. Gaps in the API's validation are fixed in
  the API, not here.
- Never put a check back in the builder before the hook: a payload the module cannot build
  at all (empty cart, bad address, failed split) is the only refusal there.

## Tenders Go As prepaid_amount; Only An Order Placed With One Declares It Later

TWO-26326. The README section "Tenders" is the specification. Tenders come only from the
`actionTwoOrderTenders` hook (`classes/TwoOrderTenders.php`); a cart rule is a discount line,
never a tender. The prepaid amount an order was placed with is recorded under `prepaid` in
`two_declared_rates`, written only when it is above zero, so every other row and body stays
byte-identical; only an order with that record declares prepaid on its updates and refunds.
Two's GET order reports a refund's `total_amount` as its `amount` plus its `prepaid_amount`.

## Tax Codes On 0% Lines Are An Aid, Never A Gate

TWO-24877, TWO-26153. Two requires a `tax_code` on every 0% line of a Spanish merchant's order.
The merchant defines the receivable; the module only reads which of the merchant's mapped rows a
line falls on. `TwoShopTaxCode` holds the rule (steps 1 to 4, the EU VAT area, the row keys, the
migration fan-out) and `applyTwoTaxCodes()` applies it in the create and update builders, before
the postprocessing hook, so a subscriber can change it. The README section "Tax codes for 0%
lines" is the specification.

- **Rows, not decisions.** The mapping is keyed `<id_tax_rules_group>|exempt`,
  `rule:<id_tax_rule>` and `<id_tax_rules_group>|none`. Step 2's rule is found with core's own
  selection (`getTwoTaxRuleForAddress()` runs `TaxRulesTaxManager`'s query against the
  `PS_TAX_ADDRESS_TYPE` address); keep the two in step. A matched row on (none) gives no code and
  never falls through. Do not add goods-versus-services logic: the merchant's groups carry it.
- **Step 4 never guesses.** A line with no group takes the one code the order's lines coded by
  steps 1 to 3 share, else none.
- **The module picks no code itself** (TWO-26153). There is no derivation and no fallback: a 0% line
  no step codes goes out without one, and Two's API refuses it for a Spanish merchant. Do not add
  country, postcode, VAT-prefix or goods-versus-services logic back; the merchant's rows carry it.
- **A mapping still stored per group** (an unmoved shop tier, a failed upgrade) is read as the
  rows the 2.7.20 upgrade would write (`readTwoTaxCodeMap()`), never treated as unreadable.
- **The hook context's `fallback_shipping_tax_code`** is steps 1 to 3 for the Default shipping
  tax code's group, never derived.
- **Never refuse and never coerce.** An uncodable line goes out without a code and Two's API
  decides. Do not add a module-side refusal, a fallback code or a rate change: the Spanish
  canonical-rate fallback this module once had was exactly that failure mode, and it is gone.
- **The buyer's VAT number** (TWO-26153) is the invoice address `vat_number` as entered
  (PrestaShop keeps no VIES result on the address), trimmed of leading and trailing whitespace by
  `getTwoBuyerVatNumber()` and otherwise sent as entered (never upper-cased, stripped or
  prefixed). Step 1 needs it non-empty. A Spanish merchant's create alone sends it as
  `buyer_vat_number`, never for a Spanish buyer company and never as an empty key. It is never an
  organisation number (TWO-40).
- **Only 0% lines are touched.** Every other payload, and every payload of an unmapped
  non-Spanish merchant, stays byte-identical; `TaxCodeSpec` holds goldens for that, and runs the
  shared case table of the design one row per case.
- **The mapping is read only for a 0% line**, and an unreadable one withholds Two in
  `hookPaymentOptions()` rather than failing the order after submit.
- **Placement's codes win on update.** They live in `two_declared_rates` under `tax_codes`,
  written only when a code resolved, so an order with none keeps its row byte-identical.
  `tax_codes_mapped` lists the keys whose codes came from steps 1 to 3: only those join step 4's
  pool on update, as at placement. A record without it (written before TWO-26153) keeps
  the old rule's shared part: the one code every other 0% line carries, else none.
- The line builder tags every line it appends (`tagTwoLineTaxKeys()`); a new kind of line needs
  a descriptor of its own, or it is resolved as an order-level charge.
