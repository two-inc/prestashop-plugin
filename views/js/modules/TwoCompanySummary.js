/**
 * Two Payment - read-only company summary inside the payment tile (TWO-25288).
 *
 * The buyer identifies their company in the ADDRESS step: a name in PrestaShop's
 * own `company` input, and an organisation number in the hidden `companyid`
 * input TwoCompanySearch creates beside it. By the time they reach the payment
 * step neither is on screen, so the tile used to offer no way at all to check
 * WHICH company is about to be credit-checked - the number in particular was
 * only ever carried in a hidden input.
 *
 * This module renders that pair back into the tile, read-only. Three capture
 * modes feed it and each maps to a different reading of the same two slots:
 *
 *   - search       name and number, both from the confirmed selection.
 *   - sole trader  name and number, pushed by TwoSoleTrader on enrolment - that
 *                  flow writes neither DOM field, it persists the pair
 *                  server-side, so there is nothing to read here.
 *   - manual entry name only, from what the buyer typed; the number slot renders
 *                  BLANK rather than absent, because a manual-entry buyer has
 *                  not supplied one and the slot going missing reads as a
 *                  rendering bug rather than as an answer.
 *
 * Display only, in every direction. It writes to `<span>`s, never to an input:
 * the values are not editable and not removable here, and the hidden `companyid`
 * input remains the sole carrier of the organisation number into the address
 * form's submission. Nothing in this file touches it.
 *
 * Everything is delegated off `document` and re-read from the DOM on each
 * render, for the reason TwoOptionalFields gives: PrestaShop re-renders the
 * payment step wholesale whenever the cart total changes, so a listener bound to
 * a tile element - or a value cached from one - does not survive.
 *
 * KNOWN RESIDUAL, and it is a limit of reading the page rather than a bug here:
 * a surcharge-line sync does not re-render the payment step, it makes core
 * FULLY RELOAD the checkout page. The reloaded address form carries the saved
 * company name but the hidden organisation-number input is recreated empty - it
 * is not one of PrestaShop's own address fields - so a search-mode buyer sees a
 * name with a blank number, indistinguishable from manual entry, while the
 * session still holds the number that will be credit-checked. Incomplete, never
 * WRONG: the tag comparison in readState() still guarantees a number is only
 * ever shown beside the name it was confirmed against. Closing it means seeding
 * this block from the server-side session company, which is a server change.
 */

class TwoCompanySummary {
    /** Root of the tile block this module owns. */
    static ROOT_SELECTOR = '.two-company-summary';

    /** Marks the two value slots inside that block. */
    static SLOT_ATTR = 'data-two-company-summary';

    /**
     * True once any instance has registered on PrestaShop's event bus.
     *
     * That bus has no `off` - the same absence TwoCompanySearch defends itself
     * against with a `_destroyed` flag - so a handler registered on it can never
     * be taken back, and a second instance would leave two live handlers behind
     * with `cleanup()` able to remove neither. Only one instance is constructed
     * today, which is exactly why this is worth a flag rather than a comment:
     * the leak would be invisible until something constructed a second one.
     */
    static _busBound = false;

    /**
     * The enrolled sole trader's pair, once TwoSoleTrader has one.
     *
     * Static rather than per-instance because it has to outlive the tile: the
     * enrolment happens on the payment step and the next cart update replaces
     * that step's DOM, taking any instance state rendered into it with it.
     *
     * @type {?{name: string, number: string}}
     */
    static _soleTrader = null;

    constructor() {
        this._stopped = false;
        this.boundOnFieldChange = this.onFieldChange.bind(this);
        this.init();
    }

    init() {
        document.addEventListener('input', this.boundOnFieldChange, true);
        document.addEventListener('change', this.boundOnFieldChange, true);
        // The tile is re-rendered by PrestaShop on these; re-render after it,
        // not during, or the block being written to is the one being replaced.
        //
        // Registered at most once per page, and the handler is a static call
        // rather than a bound method: the bus cannot be unsubscribed from, so a
        // handler that closed over `this` would pin every instance it ever saw
        // for the life of the page.
        const bus = typeof window !== 'undefined' ? window.prestashop : null;
        if (bus && typeof bus.on === 'function' && !TwoCompanySummary._busBound) {
            TwoCompanySummary._busBound = true;
            ['updatedAddressForm', 'updatedDeliveryForm', 'updatedPaymentForm', 'updatedCart'].forEach((event) => {
                bus.on(event, () => setTimeout(() => TwoCompanySummary.render(), 0));
            });
        }
        TwoCompanySummary.render();
    }

    cleanup() {
        this._stopped = true;
        document.removeEventListener('input', this.boundOnFieldChange, true);
        document.removeEventListener('change', this.boundOnFieldChange, true);
    }

    /**
     * Re-render when the buyer edits the company name by hand.
     *
     * Only the name field: the organisation number is never typed into by the
     * buyer on this path, and TwoCompanySearch calls render() directly at each
     * of the points where it changes the number itself - a `.val()` write fires
     * no event, so there is nothing here that could observe those.
     *
     * CAPTURE phase, which puts this render BEFORE TwoCompanySearch's own
     * `input` handler has cleared the disowned organisation number - that one is
     * bound to the field and therefore bubbles. So this render sees the OLD
     * number beside the NEW name, and the only reason it does not show that pair
     * is readState()'s tag comparison. That restated staleness rule is
     * load-bearing rather than defensive, and this is why.
     */
    onFieldChange(event) {
        const field = event ? event.target : null;
        if (this._stopped || !field || !field.getAttribute || field.getAttribute('name') !== 'company') {
            return;
        }
        TwoCompanySummary.render();
    }

    /**
     * Record the enrolled sole trader's company pair.
     *
     * @param {?{name: ?string, number: ?string}} pair Null forgets it.
     * @returns {void}
     */
    static setSoleTrader(pair) {
        if (!pair) {
            TwoCompanySummary._soleTrader = null;
        } else {
            TwoCompanySummary._soleTrader = {
                name: String(pair.name == null ? '' : pair.name).trim(),
                number: String(pair.number == null ? '' : pair.number).trim()
            };
        }
        TwoCompanySummary.render();
    }

    /** @returns {string} */
    static normalizeName(value) {
        return String(value == null ? '' : value).trim().toLowerCase().replace(/\s+/g, ' ');
    }

    /**
     * What the tile should currently say, read fresh from the DOM.
     *
     * The staleness rule is TwoCompanySearch's own, restated here rather than
     * trusted: that module tags the hidden input with the name the number was
     * confirmed against, and a number whose tag no longer matches the name in
     * the field belongs to a company the buyer has moved off. Showing it beside
     * the new name would assert a pairing that does not exist - the one thing
     * this display must never do - so the number blanks and the mode degrades
     * to manual.
     *
     * The sole-trader pair is consulted LAST, and only when the address form has
     * nothing at all to say. Deliberately not first: switching the toggle back
     * to `Registered business` and searching normally has to be able to replace
     * an enrolled pair, and a short-circuit above the DOM read meant a stale
     * enrolment outranked the company that would actually be credit-checked for
     * the remaining life of the page. TwoSoleTrader.setMode() forgets the pair on
     * that switch, and this ordering means the display is still right if some
     * other path back to business mode forgets to.
     *
     * @returns {{name: string, number: string}}
     */
    static readState() {
        const companyField = document.querySelector("input[name='company']");
        const name = companyField ? String(companyField.value == null ? '' : companyField.value).trim() : '';

        const orgField = document.querySelector("input[name='companyid']");
        const number = orgField ? String(orgField.value == null ? '' : orgField.value).trim() : '';

        if (name === '' && number === '' && TwoCompanySummary._soleTrader) {
            return {
                name: TwoCompanySummary._soleTrader.name,
                number: TwoCompanySummary._soleTrader.number
            };
        }

        if (!number) {
            return { name: name, number: '' };
        }

        const taggedName = orgField ? String(orgField.getAttribute('data-two-company-name') || '').trim() : '';
        if (!taggedName || TwoCompanySummary.normalizeName(taggedName) !== TwoCompanySummary.normalizeName(name)) {
            return { name: name, number: '' };
        }

        // The FIELD's spelling of the name, not the tag's. They are the same
        // name by the comparison just above, but only one of them is what the
        // address form submits, and the tile should not show a different casing
        // or spacing from the order.
        return { name: name, number: number };
    }

    /**
     * Paint the current state into the tile.
     *
     * @returns {void}
     */
    static render() {
        const root = document.querySelector(TwoCompanySummary.ROOT_SELECTOR);
        if (!root) {
            return;
        }

        const state = TwoCompanySummary.readState();
        const nameSlot = root.querySelector('[' + TwoCompanySummary.SLOT_ATTR + '="name"]');
        const numberSlot = root.querySelector('[' + TwoCompanySummary.SLOT_ATTR + '="number"]');

        // textContent, never innerHTML: the name comes from a third-party company
        // register and the buyer's own keyboard, and neither is markup.
        if (nameSlot) {
            nameSlot.textContent = state.name;
        }
        if (numberSlot) {
            numberSlot.textContent = state.number;
        }

        // TWO-25326 §7: the label reads "<name> (<number>)". The parentheses
        // belong to the number and are hidden with it, so a manual-entry
        // buyer - who supplies a name and no number (§5) - sees the name
        // alone rather than a name followed by empty brackets.
        //
        // A class rather than an inline `style.display`, because the wrapper
        // it governs is a `<span>` the stylesheet already has an opinion
        // about, and one owner beats two.
        if (root.classList) {
            root.classList.toggle('two-company-summary--has-number', state.number !== '');
        }

        // Nothing captured at all: the buyer has not named a company yet, and a
        // block of empty labels is worse than no block.
        const hasAnything = state.name !== '' || state.number !== '';
        root.style.display = hasAnything ? '' : 'none';
    }
}

if (typeof window !== 'undefined') {
    window.TwoCompanySummary = TwoCompanySummary;
}
