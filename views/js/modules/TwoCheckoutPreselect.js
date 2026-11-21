/**
 * TWO-25800: select this payment method for a buyer who arrived via the
 * product-page buy button.
 *
 * A default, never an override, and never a promise. The gates in
 * hookPaymentOptions withhold Two for a cart below the minimum, an unsupported
 * buyer country or currency; where they do, this does nothing at all and the
 * buyer chooses as they would have.
 *
 * The marker is consumed exactly once, and always: the moment the payment step
 * exists, whether or not this method is among the options. One that survived
 * an unavailable checkout would preselect an unrelated later one, which is
 * worse than never having preselected at all.
 */
(function () {
    'use strict';

    var MARKER = 'twoPreselectPayment';
    // Module scope: the buyer-choice listener has to be able to stop it, and a
    // second declaration inside run() would shadow this one and leave the
    // observer running after the buyer had already chosen.
    var observer = null;

    function wanted() {
        try {
            return window.sessionStorage.getItem(MARKER) === '1';
        } catch (e) {
            return false;
        }
    }

    function clear() {
        try {
            window.sessionStorage.removeItem(MARKER);
        } catch (e) {
            // A marker we cannot clear is one we could not have read either,
            // so we are not here.
        }
    }

    /**
     * Whether the payment step is on the page yet.
     *
     * A guest passes through address and delivery first, and PrestaShop
     * advances each step by posting to the order controller, so most renders
     * of this checkout have no payment options at all. "Not there yet" is not
     * the same as "not offered", and only the latter may consume the marker.
     */
    function mounted() {
        return document.querySelector('.payment-options') !== null;
    }

    /** This module's own option. The selectors TwoCheckoutManager already uses. */
    function radio() {
        var container = document.querySelector('[data-module-name="twopayment"]');

        if (container) {
            if (container.matches('input[type="radio"]')) {
                return container;
            }

            var within = container.querySelector('input[type="radio"]')
                || (container.closest('.payment-option')
                    && container.closest('.payment-option').querySelector('input[type="radio"]'));

            if (within) {
                return within;
            }
        }

        return document.querySelector('.payment-option input[type="radio"][data-module-name="twopayment"]');
    }

    /**
     * @return bool whether the question has been answered, and so whether to stop.
     */
    function attempt() {
        if (!mounted()) {
            return false;
        }

        var input = radio();

        // Consumed either way: this checkout has now told us whether the
        // method is on offer, and that is the one question the marker exists
        // to ask.
        clear();

        if (!input || input.checked) {
            return true;
        }

        // click(), not checked = true: the theme binds the additional
        // information panel to the change event, and setting the property
        // fires nothing, which would select the radio while leaving the tile
        // collapsed.
        input.click();

        return true;
    }

    function run() {
        if (!wanted()) {
            return;
        }

        /**
         * A choice the BUYER makes in this checkout ends it. A radio the theme
         * happens to default to is not such a choice, which is why this turns
         * on isTrusted rather than on anything being checked.
         */
        document.addEventListener('change', function (event) {
            var target = event.target;

            if (!event.isTrusted || !target || target.name !== 'payment-option') {
                return;
            }

            clear();

            if (observer) {
                observer.disconnect();
            }
        }, true);

        if (attempt()) {
            return;
        }

        if (typeof window.MutationObserver !== 'function') {
            return;
        }

        // Deliberately no deadline. Any bound would have to outlast a buyer
        // typing an address, which is unknowable, and guessing short loses the
        // preselection on exactly the slow checkouts it was meant to survive.
        observer = new window.MutationObserver(function () {
            if (attempt()) {
                observer.disconnect();
            }
        });

        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
}());
