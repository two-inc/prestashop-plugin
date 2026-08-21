/**
 * Two Payment - company/organisation number display rules (TWO-25326 §12).
 *
 * Some organisation numbers are not register numbers at all: the sole-trader
 * enrolment flow mints a SYNTHETIC identifier prefixed `TWO:` and stores it in
 * exactly the same field as a real one, because everything downstream (order
 * payload, credit decision) needs an organisation number and that prefix is
 * what carries the "this buyer is an enrolled sole trader" semantics.
 *
 * It is an internal identifier, so it must never be shown to the buyer -
 * anywhere. That is a display rule, not a data rule: the value still travels
 * in the payload and is still what the API is asked about; only the rendering
 * of it is suppressed.
 */
var TwoCompanyNumber = {
    /**
     * Compared case-insensitively: the value round-trips through a cookie, a
     * form post and the API, and this is a display gate - "shown because the
     * case did not match" is the one outcome it must not have.
     */
    INTERNAL_PREFIX: 'TWO:',

    isInternal: function (value) {
        if (typeof value !== 'string') {
            return false;
        }
        return value.trim().toUpperCase().indexOf(TwoCompanyNumber.INTERNAL_PREFIX) === 0;
    },

    /**
     * Empty string rather than null so every call site's existing
     * "do I have a number to render?" test keeps working unchanged - which is
     * also what removes the empty brackets: a site that renders
     * `Name (number)` only when the number is non-empty renders plain `Name`
     * here, never `Name ()`.
     */
    forDisplay: function (value) {
        if (value === null || value === undefined) {
            return '';
        }
        var text = String(value).trim();
        if (text === '' || TwoCompanyNumber.isInternal(text)) {
            return '';
        }
        return text;
    },

    labelFor: function (name, number) {
        var displayName = (name === null || name === undefined) ? '' : String(name);
        var displayNumber = TwoCompanyNumber.forDisplay(number);
        return displayNumber ? displayName + ' (' + displayNumber + ')' : displayName;
    }
};

if (typeof window !== 'undefined') {
    window.TwoCompanyNumber = TwoCompanyNumber;
}
