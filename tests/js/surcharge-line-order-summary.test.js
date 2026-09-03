/**
 * Doug, live-testing checkout: the order-summary line for the hidden Two
 * surcharge product was captioned "Payment terms fee" - (1) rendered as a
 * clickable link (core wraps every cart-summary product name in an anchor to
 * its product page; this one is hidden, `visibility: 'none'`, so the link
 * goes nowhere buyer-useful) and (2) missing the "- N days" suffix
 * (Magento/WooCommerce parity: "Payment terms fee - 30 days").
 *
 * The day count can't live on the catalog product's own name - one shared
 * row, concurrent carts can hold different terms (see
 * createTwoSurchargeCartProduct's docblock in twopayment.php) - so
 * fixSurchargeLineDisplay() unwraps the anchor and applies the correct label
 * from `window.twopayment.surcharge_line_label` client-side, matched by the
 * stable `surcharge_line_link_slug` in the anchor's href rather than by
 * theme markup structure. A genuine product link elsewhere in the same
 * summary (a real catalog item) must be left alone.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    stubAjax,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';
const SURCHARGE_SLUG = 'two-payment-terms-fee';
const SURCHARGE_HREF = 'https://shop.example.test/45-' + SURCHARGE_SLUG + '.html';
const OTHER_PRODUCT_HREF = 'https://shop.example.test/2-cool-widget.html';

let TwoCheckoutManager;
let $;
let ajax;

function buildCartSummary(surchargeLabel) {
    document.body.innerHTML = [
        '<div id="js-checkout-summary">',
        '  <div class="cart-summary-products">',
        '    <div class="cart-summary-line">',
        '      <a class="label" href="' + OTHER_PRODUCT_HREF + '">Cool Widget</a>',
        '    </div>',
        '    <div class="cart-summary-line">',
        '      <a class="label" href="' + SURCHARGE_HREF + '">' + surchargeLabel + '</a>',
        '    </div>',
        '  </div>',
        '</div>'
    ].join('\n');
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    ajax = stubAjax($);
    loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;
});

afterEach(async () => {
    ajax.calls.forEach((call) => {
        if (!call.aborted) {
            try {
                call.fail('abort', 'abort');
            } catch (e) {
                // some call sites wire .done()/.fail() directly - see other suites
            }
        }
    });
    await flushPromises();
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    jest.restoreAllMocks();
});

function makeManager() {
    return new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: false,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token'
    });
}

function surchargeNode() {
    return document.querySelector('.cart-summary-products a[href*="' + SURCHARGE_SLUG + '"]');
}

describe.each([
    [14, 'Payment terms fee - 14 days'],
    [30, 'Payment terms fee - 30 days'],
    [60, 'Payment terms fee - 60 days']
])('order-summary surcharge line for a %i-day term', (days, label) => {
    test('renders as plain text with the day-suffixed label, not a link', () => {
        // Given: core has rendered the surcharge line as a product-page link,
        // captioned with the static catalog name (no day count).
        buildCartSummary('Payment terms fee');
        window.twopayment = {
            order_intent_url: ORDER_INTENT_URL,
            ajax_token: 'test-token',
            checkout_host: CHECKOUT_HOST,
            surcharge_cart_line: true,
            surcharge_line_label: label,
            surcharge_line_link_slug: SURCHARGE_SLUG
        };

        // When: the checkout manager initialises (its own page-load fix-up).
        makeManager();

        // Then: the surcharge line is plain text with the correct wording.
        expect(document.querySelector('a[href*="' + SURCHARGE_SLUG + '"]')).toBeNull();
        const patched = document.querySelector('.cart-summary-products span.label');
        expect(patched.textContent).toBe(label);

        // And: the unrelated real-product link is untouched.
        const otherLink = document.querySelector('a[href="' + OTHER_PRODUCT_HREF + '"]');
        expect(otherLink).not.toBeNull();
        expect(otherLink.textContent).toBe('Cool Widget');
    });
});

test('a syncSurchargeLine response label updates the line after a term change, still no link', async () => {
    buildCartSummary('Payment terms fee');
    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST,
        surcharge_cart_line: true,
        surcharge_line_label: 'Payment terms fee - 30 days',
        surcharge_line_link_slug: SURCHARGE_SLUG
    };

    const manager = makeManager();
    expect(surchargeNode()).toBeNull(); // page-load fix already ran

    // Given: the buyer switches term, and the sync call reports the new label.
    const promise = manager.syncSurchargeCartLine(true);
    ajax.calls[ajax.calls.length - 1].succeed({
        success: true,
        changed: false,
        present: true,
        label: 'Payment terms fee - 60 days'
    });
    await promise;
    await flushPromises();

    // Core re-renders the summary fragment (still carrying its own link markup,
    // still captioned with the static, day-less catalog name) - the case
    // fixSurchargeLineDisplay must repair on every such refresh, not just once.
    buildCartSummary('Payment terms fee');
    manager.fixSurchargeLineDisplay();

    expect(document.querySelector('a[href*="' + SURCHARGE_SLUG + '"]')).toBeNull();
    expect(document.querySelector('.cart-summary-products span.label').textContent)
        .toBe('Payment terms fee - 60 days');
});

// Core's cart-summary line (checkout/_partials/cart-summary-product-line.tpl)
// links the product twice: its image in .media-left, its name in .media-body.
test('labels the name link only, so the caption appears once per summary line', () => {
    document.body.innerHTML = [
        '<div id="js-checkout-summary">',
        '  <div class="cart-summary-products"><ul class="media-list">',
        '    <li class="media">',
        '      <div class="media-left">',
        '        <a href="' + SURCHARGE_HREF + '" title="Payment terms fee">',
        '          <img class="media-object" src="/img/p/en-default-small.jpg" alt="Payment terms fee">',
        '        </a>',
        '      </div>',
        '      <div class="media-body">',
        '        <span class="product-name"><a href="' + SURCHARGE_HREF + '">Payment terms fee</a></span>',
        '        <span class="product-price">&euro;5.00</span>',
        '      </div>',
        '    </li>',
        '  </ul></div>',
        '</div>'
    ].join('\n');
    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST,
        surcharge_cart_line: true,
        surcharge_line_label: 'Payment terms fee - 30 days',
        surcharge_line_link_slug: SURCHARGE_SLUG
    };

    makeManager();

    const line = document.querySelector('li.media');
    expect(line.textContent.match(/Payment terms fee - 30 days/g)).toHaveLength(1);
    expect(document.querySelector('.media-body .product-name > span').textContent).toBe('Payment terms fee - 30 days');
    expect(document.querySelector('.media-left').textContent.trim()).toBe('');
    expect(document.querySelector('a[href*="' + SURCHARGE_SLUG + '"]')).toBeNull();
    // The image survives its unwrapped link.
    expect(document.querySelector('.media-left img')).not.toBeNull();
});

describe.each([
    ['image and name share one link', '<a href="' + SURCHARGE_HREF + '"><img class="media-object" src="/img/p/x.jpg" alt=""><span class="product-name">Payment terms fee</span></a>'],
    ['the name is not linked at all', '<a href="' + SURCHARGE_HREF + '"><img class="media-object" src="/img/p/x.jpg" alt=""></a><span class="product-name">Payment terms fee</span>']
])('a theme where %s', (scenario, markup) => {
    test('still captions the line exactly once, image intact', () => {
        document.body.innerHTML = '<div class="cart-summary-products"><ul class="media-list"><li class="media">' + markup + '</li></ul></div>';
        window.twopayment = {
            order_intent_url: ORDER_INTENT_URL,
            ajax_token: 'test-token',
            checkout_host: CHECKOUT_HOST,
            surcharge_cart_line: true,
            surcharge_line_label: 'Payment terms fee - 30 days',
            surcharge_line_link_slug: SURCHARGE_SLUG
        };

        makeManager();

        expect(document.querySelector('li.media').textContent.match(/Payment terms fee - 30 days/g)).toHaveLength(1);
        expect(document.querySelector('li.media img')).not.toBeNull();
        expect(document.querySelector('a[href*="' + SURCHARGE_SLUG + '"]')).toBeNull();
    });
});

test('an image link carrying screen-reader text is still unwrapped, image intact', () => {
    document.body.innerHTML = [
        '<div class="cart-summary-products"><ul class="media-list"><li class="media">',
        '  <div class="media-left"><a href="' + SURCHARGE_HREF + '">',
        '    <img class="media-object" src="/img/p/en-default-small.jpg" alt="">',
        '    <span class="sr-only">Payment terms fee</span>',
        '  </a></div>',
        '  <div class="media-body"><span class="product-name"><a href="' + SURCHARGE_HREF + '">Payment terms fee</a></span></div>',
        '</li></ul></div>'
    ].join('\n');
    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST,
        surcharge_cart_line: true,
        surcharge_line_label: 'Payment terms fee - 30 days',
        surcharge_line_link_slug: SURCHARGE_SLUG
    };

    makeManager();

    expect(document.querySelector('li.media').textContent.match(/Payment terms fee - 30 days/g)).toHaveLength(1);
    expect(document.querySelector('.media-left img')).not.toBeNull();
    expect(document.querySelector('a[href*="' + SURCHARGE_SLUG + '"]')).toBeNull();
});
