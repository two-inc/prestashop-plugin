# Architectural Decisions

> **Self-updating file** - AI agents should log significant decisions here.
> Add entries at the TOP (newest first).

---

## How to Add Entries

```markdown
## [YYYY-MM-DD] Decision Title

**Context**: What problem or requirement triggered this?
**Decision**: What was decided?
**Alternatives Considered**: What else was considered?
**Rationale**: Why this approach?
**Consequences**: Trade-offs and implications
```

---

## [2026-08-10] Sole-Trader Enrolment Does Not Write Back Into The Address Form

**Context**: A completed sole-trader enrolment leaves the address form untouched (TWO-40). Adopting the enrolled identity into that form was attempted three times - first the trading name into the visible `company` field plus a publish and a cookie write as a backstop, then the same without the cookie write, then the organisation number alone mirrored into the address `dni` field - and withdrawn each time. The delivered fix is the token-mint precondition change only.

**Decision**: No write-back at all. `two:sole-trader-ready` stays a bare notification with no payload, and `TwoCompanySearch` does not listen for it.

**Alternatives Considered**:
- Name + number adoption with a publish and cookie backstop (implemented, withdrawn)
- The same without the cookie write (implemented, withdrawn)
- Organisation number alone, through the already-gated `writeOrganizationToAddressIdentifiers()` writer (implemented, withdrawn)
- A true street/postcode/city autofill from the buyer-autofill response - not possible as that endpoint is consumed today, because the response carries no address payload at all; it needs an API contract confirmation before it can be designed

**Rationale**:
- The blocker is not the write itself but what `TwoCompanySearch` already does around it. Its address-form submit handler adopts the identification field's value into the submitted organisation number, and it deliberately does not tag that adoption with a confirmed company name. Its stale-pairing check then reads company-set / number-set / tag-absent as "the buyer has edited past a stale selection" and clears the selection outright - dropping the identifier, dropping the number, and posting a company clear that destroys the session company. So any identifier an enrolment writes becomes a value the buyer's own next keystroke in the company field wipes.
- Making that safe means changing that pre-existing state machine - either stopping the submit-time adoption from taking an enrolment-written identifier, or stopping the stale check from treating a marked-but-untagged pairing as buyer-stale. That is a change to behaviour this ticket did not come to change, so it belongs to its own piece of work rather than to a guard bolted onto the new write.
- Each narrowing round produced a *new* defect class rather than converging: a country clobber through the cookie writer's DOM-guessed country, an address identifier pinned to the wrong address, a mismatched name/number pairing that made the confirmed-selection check lie, an unconditional overwrite of a number the buyer had typed themselves, and a feature detection that failed open. Three fresh sets of findings in three rounds is evidence the approach was wrong, not that the guards were incomplete.
- Nothing downstream needs it: the enrolled company already reaches the order through the session record and the selection the enrolment itself publishes, which is how the payment-step path behaves.

**Consequences**:
- The buyer still sees an empty company field and an empty identification field on the address form after enrolling. The order itself is unaffected.
- Revisiting adoption requires the submit-time adoption and stale-pairing behaviour to be settled first; a street/postcode/city autofill additionally requires the API contract to be confirmed.

---

## [2026-01-22] Consolidate AI Context into CLAUDE.md

**Context**: Had both `.cursor/rules/prestashop.mdc` and `CLAUDE.md` with overlapping content.

**Decision**: Make CLAUDE.md the single source of truth with self-improvement protocols. The Cursor rules file can be removed or minimized.

**Alternatives Considered**:
- Keep both files in sync (maintenance burden)
- Keep Cursor rules as primary (not Claude-optimized)

**Rationale**: 
- CLAUDE.md is auto-read by Claude
- Self-improvement instructions enable continuous enhancement
- Single source of truth prevents drift

**Consequences**:
- Non-Claude AI models in Cursor won't get detailed rules (acceptable)
- Must keep CLAUDE.md updated (AI can self-update)

---

## [2025-11-21] End-of-Month Payment Terms

**Context**: B2B customers requested payment terms aligned with accounting cycles.

**Decision**: Add `duration_days_calculated_from: "END_OF_MONTH"` API field with STANDARD/EOM type selection.

**Alternatives Considered**:
- Hardcode EOM calculation client-side (rejected: Two backend should own this)
- Only support standard terms (rejected: customer demand)

**Rationale**: 
- EOM common in B2B invoicing
- Backward compatible via STANDARD default
- Clean UI separation

**Consequences**:
- New database column needed
- EOM limited to 30/45/60 days (API constraint)
- Requires Two backend support

---

## [2025-11-14] Separate Invoice Upload Service

**Context**: Invoice upload logic was getting complex with signed URLs and polling.

**Decision**: Create dedicated `TwoInvoiceUploadService.php` class.

**Alternatives Considered**:
- Keep in main module file (rejected: 4000+ lines already)
- Use PrestaShop service container (rejected: version compatibility)

**Rationale**: 
- Single responsibility principle
- Easier testing and maintenance
- Clear interface

**Consequences**:
- Additional file to maintain
- Must be included/autoloaded properly

---

## [2025-10-06] Triple-Layer jQuery Fallback

**Context**: jQuery not reliably available on PrestaShop 1.7.6.x with various themes.

**Decision**: Implement three fallback layers plus JavaScript-side waiting.

**Alternatives Considered**:
- Require jQuery in theme (rejected: can't control merchant themes)
- Use vanilla JS only (rejected: massive rewrite, PrestaShop uses jQuery)
- Single CDN fallback (rejected: may still race)

**Rationale**: 
- Belt-and-suspenders approach
- Guaranteed to work regardless of theme
- Minimal overhead

**Consequences**:
- Slight initialization delay in worst case
- Extra code complexity
- Pattern must be followed in all JS

---

## [2025-09-26] Server-Side Order Intent Verification

**Context**: Client-side Order Intent check could be bypassed.

**Decision**: Re-verify Order Intent server-side before creating order.

**Alternatives Considered**:
- Trust client-side only (rejected: security risk)
- Skip client-side check (rejected: poor UX)

**Rationale**: 
- Defense in depth
- Payment security is non-negotiable
- Two API is idempotent

**Consequences**:
- Extra API call at order creation
- Cached result prevents duplicate calls
- Guaranteed no order without valid intent

---

## [2025-09-26] Modular JavaScript Architecture

**Context**: Needed maintainable checkout JavaScript across PrestaShop versions.

**Decision**: Split into focused modules: Manager, OrderIntent, CompanySearch, FieldValidation.

**Alternatives Considered**:
- Single monolithic file (rejected: hard to maintain)
- ES6 modules with bundler (rejected: version compatibility)

**Rationale**: 
- Separation of concerns
- Individual component testing
- Clear responsibilities

**Consequences**:
- Multiple script files need load order management
- Inter-module communication via manager
- Priority-based registration required
