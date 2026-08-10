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

## [2026-08-10] Sole-Trader Enrolment Mirrors Only Its Organisation Number Into The Address Form

**Context**: A completed sole-trader enrolment left the address form untouched (TWO-40). A wide adoption of the enrolled identity into the form - the trading name into the visible `company` field, plus a publish and a cookie write as a backstop - was built and then withdrawn.

**Decision**: Keep only the organisation-number mirror into the address `dni` field, performed through the existing `writeOrganizationToAddressIdentifiers()` writer. That writer is already gated on the merchant's "Autofill company address" setting (`PS_TWO_ADDRESS_LOOKUP`) and writes with no `input`/`change` announcement, which is what makes the reduced version safe. The gate is that setting - explicitly NOT where company search is mounted: the setting happens to be forced off when search moves to the payment tile, but the two are separate questions and the coincidence must not be relied on.

**Alternatives Considered**:
- The wide adoption, gated on the mount being the address form (implemented, then withdrawn)
- A true street/postcode/city autofill from the buyer-autofill response (not possible today - that endpoint carries no address payload, so it needs an API contract confirmation first)

**Rationale**:
- The visible-name write entangles with three live state machines at once: the stale-selection clear driven by that field's own `input` handler, the manual-entry guard, and `hasConfirmedSelection()`'s name/number tag agreement.
- Each fix round produced a *new* defect class rather than converging - a country clobber through the cookie writer's DOM-guessed country, an `id_address` pinned to the wrong address, a mismatched name/number tag that made `hasConfirmedSelection()` lie, and a nameless sole trader's enrolment destroyed by their own next keystroke.
- Nothing downstream needs the visible field written: the enrolled company already reaches the order through the session cookie and the selection the enrolment itself publishes, which is how the payment-step path and the other platform plugins behave.

**Consequences**:
- The buyer still sees an empty company field on the address form after enrolling; the order itself is unaffected.
- A synthetic internal identifier is refused rather than mirrored, because the identification field is saved onto the address and can be printed.
- Revisiting the name write needs the state machine untangled first; any address autofill needs the API contract confirmed.

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
