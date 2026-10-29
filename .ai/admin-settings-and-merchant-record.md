---
description: "Admin settings validation, buyer fee-quote gating, API-key save verdicts and the cached merchant record"
---

# Admin Settings, Fee Quotes And The Merchant Record

Moved verbatim from `AGENTS.md`.

## Admin Settings Fail Loud: An Unrecognised Stored Value Is Never Priced

The standard for EVERY module setting, not only the surcharge method.

- Save refuses it. The form validator rejects a posted value outside the
  field's known set before anything is written, so a crafted POST cannot store
  a value nothing understands. Only the field's unset key persists as the
  default.
- Read paths raise. The settings getter is the single choke point: it maps the
  unset key to the default and throws for anything else. Callers that price a
  fee or build an order let that throw.
- Gates catch it. The payment-option hook withholds Two and nothing else; the
  hooks that render on every request take the contained read and degrade to
  "no fee" rather than 500ing the page. The getter logs the offending value
  once per request, so the catchers stay quiet.
- Buyer copy stays generic. The buyer sees the existing "not available for this
  order" wording. A setting name, a stored value or an enum key never reaches
  the storefront — those belong in `ps_log` and in the admin form's own error.
- The admin form keeps the lenient read, so a shop with a corrupt stored value
  still renders a correctable settings page.

Degrading a junk value to a working default is the failure this replaces: it
prices an order under a configuration nobody chose, and nobody is told.

## A Failed Buyer Fee Quote Withholds Two At Checkout Only

The same failure means different things on the two surfaces.

- **Checkout.** `isTwoSurchargeQuotableForCart()` is the ONE withholding gate, and
  `hookPaymentOptions()` returns `[]`, so the tile is absent from the list rather
  than offered-and-disabled. A fee quote that does not resolve withholds Two, because
  the alternative is an order created with no surcharge at all — a silent undercharge.
  The reason is logged at error level, once per render.
- **One term, never all of them**: the selected term, else the merchant default. The
  FX loop in the same predicate stays term-independent; a per-term condition inside it
  took whole stores offline once already (TWO-25276).
- **A quoted zero, an empty basket, a disabled surcharge and a term that prices no
  surcharge are answers, not failures**, and withhold nothing. Whether a term prices a
  surcharge is ONE predicate shared by the gate and the line builder — a cap with no
  percentage behind it prices nothing, and in fee-difference mode so does the default
  term unless a fixed amount rides along. If the two sides ever judge that separately,
  the gate offers Two and order create then refuses it over a fee of zero.
- **The order-create parity gate owns the term the buyer actually orders.** The gate
  runs at render, and the buyer can switch term inside the rendered tile; an
  unresolvable quote for the ordered term is a parity failure there and refuses the
  order, because zero on both sides otherwise reads as agreement.
- **Every surcharge quote shares one ceiling** (`API_TIMEOUT_SURCHARGE_PRICING`), not
  the render-path default, and the gate has no ceiling of its own. It favours
  availability over render latency: a shop whose pricing is merely slow keeps the tile
  rather than having it withheld over a fee the order would have priced correctly. The
  cost during an outage is one such timeout per payment-options render, and that is
  accepted.
- **The cross-request cookie cache holds the CHARGED term's successful quote only.**
  The whole cookie shares a 4KB browser cap, so a slot per offered term would let a
  chip-preview render push the shopper's session over it and lose their cart. Chip
  previews stay on the request-scoped cache, which does hold a null for the rest of
  that request.
- **Admin.** The configuration page previews merchant fee RATES from a different
  endpoint and degrades to a notice. `hookPaymentOptions()` never runs in admin, so a
  pricing outage can neither lock the merchant out nor blank that preview.
- The per-term checkout chips stay fail-soft: they show a number, they never decide
  one, so a failed quote zeroes that chip only.

## An Admin Save Stays Possible; The Buyer Path Fails Closed

**No verdict from the save-time API-key check blocks the General save**.
A connection failure, a timeout and a 5xx judge nothing about the key, and the form
re-renders from POST, so a blocked save looks stored while nothing was written and
leaves the merchant unable to store the replacement key that would fix the outage,
or even change the vendor name while it lasts. The verdict is reported as a warning
beside the save confirmation instead.

- **A key Two rejected (401/403) is the one submitted value a save discards** — the
  stored key is kept and the warning says so.
- The merchant short name is derived from the verification response, never
  submitted, so a save that resolved no merchant keeps the stored one.
- Storing a different key or environment refreshes the cached merchant record and
  never clears it. A merchant with two shops who cycles their key and
  updates only one must not have the other forget the terms, fees and minimum its
  admin controls are built from, and a typo saved during an outage must be
  recoverable by pasting the right key back. A record fetched for another key is
  withheld, not dropped.
- **A 200 carrying no merchant `id` is not a verified key** — a proxy, a captive
  portal or a maintenance page answers 200 too. It categorises as `error`, which
  rejects no key, so it withholds nothing. A record with an `id` but no
  short name IS verified; the empty short name withholds Two through its own gate.

## The Merchant Record Never Expires, And Only A Rejected Key Hides The Tile

The cached record — offerable terms, default term, buyer surcharge, platform
minimum, buyer countries, invoice-upload flag — has no expiry and is never evicted, on
any failure path. Only a successful refresh replaces it, and only from an event: the
API-key/environment save, `controllers/front/cron.php`, the Diagnostics button, or a
read standing in for a schedule that has stopped.

- **Nothing but the API-key verdict may withhold Two from the buyer, and only its
  DEFINITIVE rejections do** — `isDefinitiveFailureStatus()`: `invalid_key` and
  `not_configured`. An unresolvable record, a 500, an empty offer set, an
  unreachable or erroring key check: none of them hide the tile. That predicate is
  the one definition of the set; the payment POST's gate and the company-search
  affordance ask through it too, so nothing re-lists the categories.
- **An unresolved offer set offers the buyer NO term, never a substitute.**
  `getAvailablePaymentTerms()` reports what the record reports, or nothing;
  `getConfigurableTermSet()` is the admin-side question and is the only thing the
  hardcoded `PAYMENT_TERMS_OPTIONS` preset feeds. Do not compose a term for a buyer
  from that preset or from `DEFAULT_PAYMENT_TERM_DAYS` — a merchant may not hold it.
  With no offered term the tile renders with no term block at all, no fee line
  label is composed, and `controllers/front/payment.php` refuses the submission
  rather than book an order against a term nobody granted.
  `getDefaultPaymentTerm()` is null in that state and the browser seam publishes
  0, so neither PHP nor the checkout JS can name a day count.
- **A chosen term that is not offered is refused, never defaulted.** The save-term
  AJAX (`ajaxProcessSavePaymentTerm()`) answers `success:false` and records nothing for
  a day count the merchant does not offer, and the payment POST refuses before any
  `/v1/order` when the saved term is no longer offered (the merchant withdrew it since),
  clearing the saved choice so a retry books what the reloaded page shows.
  `getUnofferedChosenPaymentTerm()` is the one test for it. Only a buyer who never chose
  a term gets `getDefaultPaymentTerm()`; `getSelectedPaymentTerm()` stays the display and
  pricing resolver and is not a substitute for that refusal. Pinned by `OrderTermWireSpec`.
- **A record past `MERCHANT_RECORD_STALE_AFTER` (26h) says the schedule is not
  running.** A read then refreshes it itself, at most once an hour and on a 2-second
  cap, and serves the record it holds either way. Staleness withholds nothing.
- **No admin control is gated on any of this**, and no failure blocks a save.
- A shop where no fetch has ever succeeded has nothing to serve, so a read retries on
  the short backoff until one does.
- **The record is fetched for the API key's merchant.** `PS_TWO_MERCHANT_ID` and the
  short name are written only by a verified General save, so a seeded, cloned or migrated
  shop can hold an empty or stale id and no short name. When either is empty or the fetch
  answers 404, the refresh resolves the merchant from the key (`verify_api_key`), fetches
  that record and stores the resolved id and short name (a response without a short name
  keeps the stored one). Once both are stored, a refresh makes no extra call. The
  refresh's time cap applies to each call, so a resolving refresh can take up to twice it.
