/**
 * TWO-25800: the product-page buy button.
 *
 * Owns exactly one add-to-cart attempt and decides from that attempt's own
 * response. Nothing here places an order, and nothing needs an order to exist.
 *
 * The request is the theme's OWN form, serialised whole. The standard product
 * form carries no id_product_attribute, only group[...] controls, so anything
 * naming the fields it wants sends the default combination however carefully
 * it is written. Serialising names nothing and cannot rot as themes differ.
 */
(function () {
    'use strict';

    var MARKER = 'twoPreselectPayment';

    /**
     * The latch, held here rather than on the button.
     *
     * Choosing a combination mid-flight re-renders the fragment this button
     * lives in, and the replacement node arrives without the disabled
     * attribute - so a latch stored on the element is thrown away exactly when
     * it is doing its job, and the next click starts a second add. The buyer
     * pays for two units of one intended purchase.
     *
     * The attribute stays as the visible affordance; this is what decides.
     */
    var inFlight = false;

    /**
     * How long the button waits for core before giving up on it.
     *
     * Unbounded, a request that never answers leaves the latch held for as
     * long as the page is open: the button sits disabled, saying nothing, and
     * the buyer has no way to retry it. The theme's own add to cart stays
     * clickable through the same outage, so anything unbounded here is
     * strictly worse than not having the button at all.
     */
    var TIMEOUT_MS = 15000;

    /**
     * Whether a hand-off recorded here could ever be read at the checkout.
     *
     * sessionStorage is keyed by the FULL origin. A shop with SSL enabled but
     * not everywhere serves this product page over http and the checkout over
     * https, which are different origins, and a marker written here would
     * simply never be found - the preselection failing silently on every such
     * shop. Where that is so, nothing is recorded at all: the button adds to
     * the basket and the buyer picks their method, which is a whole feature
     * working rather than half of one.
     */
    function handoffReaches(button) {
        var checkout = button.getAttribute('data-two-checkout-url');

        if (!checkout) {
            return false;
        }

        try {
            return new URL(checkout, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    /**
     * Resolved at click time, never cached: choosing a combination re-renders
     * both the add-to-cart and the additional-info fragments, so a reference
     * taken earlier points at a form that has left the page.
     */
    function productForm(button) {
        return button.closest('form') || document.querySelector('#add-to-cart-or-refresh');
    }

    /**
     * The form's own fields, plus what core's cart controller needs to treat
     * this as an add it should answer rather than redirect.
     *
     * A customisable product can carry file inputs, which have no meaning in a
     * urlencoded body; they belong to customisation the buyer has already
     * saved against id_customization, which travels as a plain field.
     */
    function body(form) {
        var params = new URLSearchParams();

        new FormData(form).forEach(function (value, name) {
            if (typeof value === 'string') {
                params.append(name, value);
            }
        });

        params.set('add', '1');
        params.set('action', 'update');
        params.set('ajax', '1');

        return params;
    }

    /**
     * Success is this response saying so, never the absence of an exception.
     *
     * A refusal core can explain - a stock limit, most often - answers with a
     * populated `errors` and no `success` key at all, so anything short of an
     * explicit true is a failure.
     */
    function succeeded(payload) {
        if (!payload || payload.success !== true) {
            return false;
        }

        return !(Array.isArray(payload.errors) && payload.errors.length > 0);
    }

    function firstError(payload) {
        if (payload && Array.isArray(payload.errors) && payload.errors.length > 0) {
            return String(payload.errors[0]);
        }

        return '';
    }

    /** The buyer is told what core said, in core's own words. */
    function report(button, message) {
        var holder = button.parentNode
            && button.parentNode.querySelector('.two-product-button__error');

        if (!holder) {
            return;
        }

        holder.textContent = message;
    }

    function unlatch(button) {
        button.removeAttribute('disabled');
    }

    function release() {
        inFlight = false;

        var buttons = document.querySelectorAll('[data-two-buy-now][disabled]');

        Array.prototype.forEach.call(buttons, unlatch);
    }

    function handle(button) {
        var form = productForm(button);
        if (!form || typeof window.fetch !== 'function') {
            return;
        }

        // Latched for the duration of this one attempt, so a second click
        // cannot open a second one and add the item twice.
        inFlight = true;
        button.setAttribute('disabled', 'disabled');
        report(button, '');

        // Abort rather than merely stop waiting, so a hung request is not left
        // holding a connection the buyer has already given up on.
        var controller = typeof window.AbortController === 'function'
            ? new window.AbortController()
            : null;
        var expiry = window.setTimeout(function () {
            if (controller) {
                controller.abort();
            }
        }, TIMEOUT_MS);

        var request = {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body(form)
        };

        if (controller) {
            request.signal = controller.signal;
        }

        window.fetch(form.action, request).then(function (response) {
            return response.json();
        }).then(function (payload) {
            window.clearTimeout(expiry);

            if (!succeeded(payload)) {
                // Nothing was added, so nothing is remembered and the buyer
                // keeps the page they are on.
                release();
                report(button, firstError(payload));
                return;
            }

            // Written only now, on a confirmed add, and only where the
            // checkout can actually read it. Core's redirect drops the query
            // string, so the intent cannot travel in the URL; the tab is where
            // it lives, and it dies with the tab.
            if (handoffReaches(button)) {
                try {
                    window.sessionStorage.setItem(MARKER, '1');
                } catch (e) {
                    // The buyer still reaches checkout, they just pick the
                    // method themselves.
                }
            }

            window.location.assign(form.action);
        })['catch'](function () {
            // An attempt that never resolved is not a success. The latch
            // releases so the buyer can try again, and nothing is remembered.
            //
            // It also SAYS so. A rejected or abandoned request used to leave
            // the button quietly re-enabling itself, which looks to the buyer
            // exactly like a click that did nothing - the one outcome they
            // cannot act on.
            window.clearTimeout(expiry);
            release();
            report(button, button.getAttribute('data-two-unreachable') || '');
        });
    }

    /**
     * Delegated, never bound to the node: choosing a combination re-renders
     * the fragment this button lives in, so a bound listener would be
     * discarded with the element and its replacement would be inert.
     */
    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest
            ? event.target.closest('[data-two-buy-now]')
            : null;

        if (!button) {
            return;
        }

        event.preventDefault();

        if (inFlight) {
            // A replacement node rendered mid-flight carries no disabled
            // attribute of its own, so it is marked here too and the buyer
            // sees the state the latch is actually in.
            button.setAttribute('disabled', 'disabled');
            return;
        }

        handle(button);
    });

    /**
     * Coming back with the browser's Back button restores this page from the
     * back-forward cache exactly as it was left - including a button still
     * latched against an attempt that finished long ago, which would be dead
     * to every further click.
     */
    window.addEventListener('pageshow', function (event) {
        // Restores only. pageshow also fires on an ordinary load, where
        // nothing of ours is latched yet but a theme may have rendered the
        // control disabled on purpose - for an unpurchasable product, say -
        // and releasing that would be this script overriding the shop.
        if (!event.persisted) {
            return;
        }

        release();
    });
}());
