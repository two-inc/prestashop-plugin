/**
 * Two Payment - optional buyer reference fields
 *
 * The fields render inside the Two payment tile, which PrestaShop emits as the
 * payment option's "additional information" block. That block is a SIBLING of
 * the module's payment form, never a child of it, so the visible inputs are not
 * part of the form's submission. Each one therefore has a hidden twin declared
 * as a payment option input.
 *
 * Everything is delegated off `document` rather than bound to the inputs: the
 * payment step is re-rendered wholesale by PrestaShop whenever the cart total
 * changes (voucher added, surcharge line synced), which would silently drop
 * listeners bound to the replaced elements.
 */
class TwoOptionalFields {
    constructor(options) {
        options = options || {};
        this.i18n = options.i18n || {};
        this.boundOnInput = this.onInput.bind(this);
        this.boundOnSubmit = this.onSubmit.bind(this);
        this.init();
    }

    init() {
        document.addEventListener('input', this.boundOnInput, true);
        document.addEventListener('change', this.boundOnInput, true);
        // Capture phase: PrestaShop's own checkout script submits the form by
        // clicking the hidden submit button inside it, so a bubble-phase
        // listener would run after the browser has already started the
        // submission on some themes.
        document.addEventListener('submit', this.boundOnSubmit, true);
    }

    cleanup() {
        document.removeEventListener('input', this.boundOnInput, true);
        document.removeEventListener('change', this.boundOnInput, true);
        document.removeEventListener('submit', this.boundOnSubmit, true);
    }

    static visibleFields() {
        return document.querySelectorAll('[data-two-optional-field]');
    }

    static hiddenTwin(field) {
        const target = field.getAttribute('data-two-optional-target');
        if (!target) {
            return null;
        }

        return document.querySelector('input[type="hidden"][name="' + target + '"]');
    }

    onInput(event) {
        const field = event.target;
        if (!field || !field.getAttribute || !field.getAttribute('data-two-optional-field')) {
            return;
        }

        this.mirror(field);
        TwoOptionalFields.setFieldError(field, '');
    }

    mirror(field) {
        const twin = TwoOptionalFields.hiddenTwin(field);
        if (twin) {
            twin.value = field.value;
        }
    }

    mirrorAll() {
        TwoOptionalFields.visibleFields().forEach((field) => this.mirror(field));
    }

    onSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !TwoOptionalFields.isTwoPaymentForm(form)) {
            return;
        }

        // A browser autofill or a theme script can set a value without ever
        // firing input/change.
        this.mirrorAll();

        const invalid = this.findInvalidField();
        if (!invalid) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        TwoOptionalFields.setFieldError(
            invalid,
            this.i18n.invalid_invoice_email || 'Please enter a valid invoice email address, or leave the field empty.'
        );
        if (typeof invalid.scrollIntoView === 'function') {
            invalid.scrollIntoView({ block: 'center' });
        }
        if (typeof invalid.focus === 'function') {
            invalid.focus();
        }
    }

    /**
     * The only optional field that can be wrong rather than merely empty.
     */
    findInvalidField() {
        let invalid = null;
        TwoOptionalFields.visibleFields().forEach((field) => {
            if (invalid || field.getAttribute('data-two-optional-field') !== 'invoice_email') {
                return;
            }
            const value = (field.value || '').trim();
            if (value !== '' && !TwoOptionalFields.isEmail(value)) {
                invalid = field;
            }
        });

        return invalid;
    }

    /**
     * Identified by the hidden twins it carries rather than by its action URL
     * or id, both of which vary: the option id is assigned by PrestaShop, and
     * the action is a friendly-URL-dependent module link.
     */
    static isTwoPaymentForm(form) {
        const fields = TwoOptionalFields.visibleFields();
        for (let i = 0; i < fields.length; i += 1) {
            const target = fields[i].getAttribute('data-two-optional-target');
            if (target && form.querySelector('input[type="hidden"][name="' + target + '"]')) {
                return true;
            }
        }

        return false;
    }

    static isEmail(value) {
        // Structural only - the authority on whether an address is acceptable
        // is the server-side Validate::isEmail check in the payload builder.
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    static setFieldError(field, message) {
        const container = field.closest('.two-optional-field');
        if (!container) {
            return;
        }
        const holder = container.querySelector('.two-optional-field__error');
        if (!holder) {
            return;
        }
        holder.textContent = message;
        holder.style.display = message === '' ? 'none' : '';
        container.classList.toggle('two-optional-field--invalid', message !== '');
    }
}

if (typeof window !== 'undefined') {
    window.TwoOptionalFields = TwoOptionalFields;
}
