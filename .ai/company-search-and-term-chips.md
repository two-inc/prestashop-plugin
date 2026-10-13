---
description: "Company-search panel focus rules, signup popup focus handling, and the payment-term chip accessibility contract"
---

# Company Search, Signup Popup And Payment-Term Chips

Moved verbatim from `AGENTS.md`.

## Company Search: This Module's Own Implementation

`views/js/modules/TwoCompanySearch.js` is this module's own panel. The Magento and
WooCommerce plugins each carry a copy of one framework-free module, and nothing of
that is vendored here, so a fix to shared panel behaviour on those two platforms is
not a fix here, and vice versa. Never describe a change as cross-platform without
having made it in each module that carries the behaviour.

**The unsupported-country gate withdraws the SEARCH and nothing else.** It hides
the Registered Company chip and the query row; the panel still opens and manual
entry and the sole-trader route stay offered, because they are the only way a
buyer in an uncovered country has to name their company at all. The
country it reads is the wider company-search coverage, one global list — not the
Sole Trader chip's own per-country registry lookup, which is a different and
smaller list.

**The chip row is rendered whenever it offers a mode the buyer is not already
in**, not merely whenever it holds two chips. A lone chip for the current mode is
no choice; a lone chip for a different mode is the buyer's whole way out, which is
exactly the state an uncovered country with no sole-trader route leaves.

**`syncQueryFieldSuppression()` is the single authority on SHOWING the query
row.** The country gate only ever hides it and delegates back for the restore, so
the sole-trader condition is stated in one place and that method stays a pure
function of the selected chip.

**Focus arriving on the company-name field opens the panel**, with the caret in the
query field — or on the first chip on screen where the query row is not rendered,
so no state opens the panel with focus outside it, where neither the
Escape-to-close nor the close-on-focus-leave handler can see a keystroke.
`setupCompanyFieldOpeners()` binds focus, mousedown and keydown to one
`openDropdown()`.

**In a country the search does not cover, a printable key takes the buyer into
manual entry and keeps the character.** The panel opens onto a chip there, a chip
is a `<button>` that swallows text, and this field is a readonly search trigger
until manual entry takes it over — so every character typed was lost with nothing
on screen to say so. Manual entry is the only state this field accepts typing in,
and in that country it is the only route to naming a company at all.
Space and Enter are excluded: both activate the focused chip.

**Closing the panel puts focus back on the company-name field** — Escape, a
pointer press outside it, a company adopted from the results, manual entry taking
the field over, and a sole-trader signup that answers or is abandoned.
The field's own focus opener is held off for that one programmatic focus alone, so
any keydown on the field, a pointer press on it, or focus arriving from anywhere
else brings the popover straight back.

**A signup launch holds that FOCUS opener off until a `focus`+`focusin` pair
lands on the parked field, or the buyer works the field.** The window's own
`focus` is what the pair is read against: with one behind it the pair is the
re-fire a browser sends at whatever was focused when the window comes back, which
is the buyer returning to the tab and not the buyer choosing this panel, so it is
spent and opens nothing - and it is bound to the window's return, not to the
popup's close, so there is no bound on how long it takes. With no window `focus`
behind it the pair is a buyer arriving on the field by Tab, and it ends the hold
AND opens the panel, so a keyboard-only buyer is never left without the control.
Every focus this module moves itself - the launch's park, a close returning focus,
manual entry taking the field over - is neither half: it moves under `_closingSelf`,
which the opener skips and which voids any half-pair standing. jQuery simulates
`focusin` from a capture-phase `focus` listener on the document, so a real focus
reaches these handlers as `focusin` then `focus`, and a jQuery `trigger('focus')`
on the already-focused field reverses them; the rule is written against the pair's
second half rather than against either name, so neither order changes which half
spends the hold, and both halves carry the same guards, so neither can refuse a half
the other armed. The hold outlives the flight settling; a `pointerdown`, `keydown`
or `click` on the field ends it at once, and the pointer and keyboard openers are
live throughout.

LIMITATION: focus moved by the theme or by PrestaShop itself - a re-render
restoring focus, a validation handler - arrives with no window `focus` behind it,
so the opener reads it as a buyer's Tab arrival and opens the panel.
`TwoSoleTrader.js` records the same limitation for the popup it takes down.

LIMITATION: a buyer who returns to the tab onto some other control and then Tabs
to the company field loses that one Tab. Nothing but a gesture on the field clears
the window's `focus`, so the pair that Tab brings is read as the return's own
re-fire: it ends the hold and opens nothing. A click or a printable key on the
field brings the popover straight back.

**The close-on-focus-leave path is the exception, and deliberately so.** It only
fires once focus has settled on another control, so taking focus back would undo
the buyer's own Tab (TWO-25326). The same holds for the closes nothing in the
buyer's hands reached: a re-render, a country change mid-select, and another
popover claiming the single open slot.

**A press on the panel's own dead space is a no-op.** Its default action would
blur the caret out of the query field, and the panel's `mouseup` reclaim — which
exists for a scrollbar drag, where the browser drops focus with no cancellable
default — would then place focus the buyer never moved. So the press is
cancelled, except on a control, which a press is entitled to focus, and except
on a scrollbar, where cancelling would stop the drag scrolling the results.

**A pointer press outside the popover takes focus back only where the press left
it nowhere**, and one tick later rather than in the handler: the press's own
default action runs after the handler and either focuses what it hit or clears
focus entirely, so focusing the field from the handler is simply undone. Neither
default action exists in jsdom, which is why this needed a real browser.

**The open panel takes the field's tab stop** — `tabindex="-1"` while it is up, and
on close the field's PRIOR value restored exactly, which is removal when there was
none (TWO-25503). Without it the focus opener is a keyboard trap: the opener puts
the caret in the query field, Shift+Tab returns to the field, and the opener pushes
focus forward again, so the buyer cannot get back past the control (WCAG 2.1.2).

**Only one popover is open, page-wide.** Opening one closes whichever other one
was open, enforced at open time rather than inferred from focus leaving the
first: a real pointer press on a second control need not deliver a focus event to
what it hits. The popover that closes gives its own field's tab stop
back before the newly opened one takes its. A pointer press outside the open
popover closes it too, with the company field counted as inside the control.
Core renders one editable address form per step, so a shop cannot currently put
two of these controls on a page; the guards are held identical to the other
platforms all the same, because divergence here is what makes the next
alignment change expensive.

The field is `readonly` in search mode, never `disabled`: a readonly input still
submits its value, still takes focus and is still a tab stop, and it IS
PrestaShop's own address field.

## What Focus Landing on the Checkout Does to an Open Signup Popup

Every focus on the checkout is classified once, whether a popup is up or not
(TWO-25658):

- **The chip that opened the popup on screen leaves it exactly as it is.** Only an
  activation of that chip moves it, and that chip's own click handler owns it. The
  exemption is per capture, held as the launching chip itself; a re-render's
  rebuilt chip inherits it by capture, a sibling capture's chip never does.
- **Any other control closes an open popup**, and the popover closes itself when
  focus lands outside it.
- **A different capture's Sole trader chip gets a popup of its own**, raised
  through that chip's own click handler so a launch is spelled out in one place.

Only focus this module moves is quiet. Focus moved by the theme, another module or
the browser — a validation jump, a restored scroll position, a password-manager
fill — reads as the buyer and takes the popup down. Close only, so the enrolment
survives and the chip reopens it.


## The Payment-Term Chips Owe The Radio-Group Keyboard Contract

The chip row advertises itself as a radio group — a `radiogroup` container, `radio`
chips, an `aria-checked` state — so it owes the W3C pattern's keyboard behaviour.
Advertising the role without it is the defect: a screen-reader buyer is
told "radio group" and finds none of the interaction that implies.

- **One tab stop, on the checked chip.** A roving `tabindex` keeps exactly one chip
  tabbable. A selection matching no offered chip falls back to the first, so no state
  drops the group out of the tab order.
- **The arrow keys move the checked selection**, wrapping at both ends, with Home and
  End for the first and last term; focus and selection move together, and the handler
  returns without preventing the default for an arrow carrying alt, ctrl or meta,
  since swallowing those breaks the browser's own shortcuts.
- **One predicate sets the selected class, `aria-checked` and the tab stop**, so the
  visual and programmatic states cannot drift apart.
- **Selection follows focus, so the persist is coalesced on the keyboard path.** Each
  arrow key changes the term, which persists it and re-quotes the fee; without
  coalescing an arrow sweep is one round trip per keystroke. A click persists at once.
- **A chip's visible text states the term in full**, so a standard-term chip carries
  no `aria-label` of its own: one restating "30 days" would risk WCAG 2.5.3. Only an
  end-of-month chip is named, because `EOM+30` needs spelling out — see below.
- **A single offered term is a `disabled` chip**: `disabled` takes it out of the tab
  order whatever its `tabindex`, and the arrow handler ignores a group of fewer than
  two enabled chips. It is therefore not reachable or announceable by keyboard at all.

## A Chip States Its Term Type, Not Just A Day Count

An end-of-month term falls due that many days after the end of the month, so a chip
reading "30 days" on a shop configured that way states the wrong due date.
The visible text is `30 days` under standard terms and `EOM+30` under end of month.

Only the end-of-month chip carries a `title` and an `aria-label`, and both read
`EOM+30: pay 30 days after the end of the month`. The name opens with the visible
token because WCAG 2.5.3 requires the accessible name to contain the visible text, so
the phrase may not be reordered to put the explanation first. It is one translated
sentence with the day count substituted, not a concatenation of translated fragments:
word order differs by language.

That name states the surcharge too, because an `aria-label` replaces the whole
accessible name and the amount rendered inside the chip is then announced nowhere:
`EOM+30: pay 30 days after the end of the month, plus a 7.25 EUR surcharge`. It is a
second whole sentence rather than the first with a clause appended, and its
placeholders are numbered because the day count and the amount are different values.
The quote lands after the chips are built, so each chip keeps both sentences on itself
and the name is restated when the amounts arrive — a failed or absent quote puts it
back to the one claiming no amount, alongside the blank it leaves in the chip.
