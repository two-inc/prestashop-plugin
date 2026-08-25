/**
 * TWO-25326 §7.1 follow-up: three browser-side defects found running a real
 * checkout with "Enable company search in address entry" set to "No" (the
 * company-search control mounted in the payment tile instead).
 *
 * Every test builds the tile WITHOUT an address form: PrestaShop only renders
 * `select[name='id_country']` on the address step, not the payment step, so a
 * test with the address form present would pass for the wrong reason.
 *
 *   Bug 1  the control renders wider than every other field in the tile
 *   Bug 2  the "go back to your billing address and search for your company"
 *          prompt is shown even though the search is right there in the tile
 *   Bug 3  typing into the control's query field fires no search at all
 */

'use strict';

const {
    loadCompanySearch,
    loadScript,
    installStylesheet,
    stubAjax,
    releaseWidgets,
    panelParts,
    shown,
    resultTexts
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

const SEARCH_RESPONSE = {
    items: [
        { name: 'Example Trading Ltd', lookup_id: 'lk-1', national_identifier: { id: '11111111' } }
    ]
};

let TwoCompanySearch;
let $;
let ajax;

/** The payment step in tile mode: `paymentinfo.tpl` renders behind
 * `{if $company_search_tile}`, no address form anywhere on the page. */
function buildTileOnlyPaymentStep() {
    document.body.innerHTML = [
        '<div class="two-payment-container">',
        '  <section class="two-payment-info" style="display: none;">',
        '    <p class="two-subtitle">Buy now, pay later - instant credit</p>',
        '    <p class="two-payment-message"></p>',
        '  </section>',
        '  <div class="two-tile-company-search" id="two-tile-company-search">',
        '    <div class="form-group">',
        '      <label for="two_tile_company">Company</label>',
        "      <input type='text' class='form-control' id='two_tile_company' name='two_tile_company' autocomplete='off' />",
        '    </div>',
        '  </div>',
        '  <div class="two-optional-fields" id="two-optional-fields">',
        '    <div class="two-optional-field two-optional-field--po_number">',
        '      <label class="two-optional-field__label" for="two-field-po_number">PO number</label>',
        "      <input class='two-optional-field__input form-control' id='two-field-po_number' type='text' />",
        '    </div>',
        '  </div>',
        '</div>'
    ].join('\n');
}

/** Mount the shared control on the tile field, exactly as the manager does. */
function mountOnTile() {
    return new TwoCompanySearch({
        checkoutHost: CHECKOUT_HOST,
        companySearchInAddressArea: false,
        companyFieldSelector: '#two_tile_company'
    });
}

function openTilePanel() {
    $('#two_tile_company').trigger('mousedown');
    return panelParts().query;
}

function typeTileQuery(value) {
    const query = panelParts().query;
    query.val(value);
    query.get(0).dispatchEvent(new window.Event('input', { bubbles: true }));
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    buildTileOnlyPaymentStep();
    ajax = stubAjax($);
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    document.body.innerHTML = '';
    delete window.twopayment;
});

describe('Bug 3: the tile-mounted control actually searches', () => {
    beforeEach(() => {
        jest.useFakeTimers();
    });

    afterEach(() => {
        jest.useRealTimers();
    });

    /**
     * `getCurrentCountry()` read only `select[name='id_country']`, absent on
     * the payment step, so `searchCompanies()` took its `countryUnresolved`
     * branch every keystroke: no request, and a prompt to pick a country
     * "above" where there is no country control at all.
     */
    test('typing puts a request on the wire for the billing address country', () => {
        window.twopayment = { billing_country: 'GB' };
        mountOnTile();
        openTilePanel();

        typeTileQuery('Example Trading');
        jest.advanceTimersByTime(400);

        expect(ajax.calls).toHaveLength(1);
        expect(ajax.last().url).toContain('/companies/v2/company?');
        expect(ajax.last().url).toContain('country=GB');
        expect(ajax.last().url).toContain('q=Example+Trading');
    });

    test('the results the API returns are rendered in the tile panel', () => {
        window.twopayment = { billing_country: 'GB' };
        mountOnTile();
        openTilePanel();

        typeTileQuery('Example Trading');
        jest.advanceTimersByTime(400);
        ajax.last().succeed(SEARCH_RESPONSE);

        expect(resultTexts()).toContain('Example Trading Ltd (11111111)');
    });

    /** Guards against the fix becoming an unconditional default country: a
     * wrong register is worse than none. */
    test('with no country resolvable anywhere it still declines to search', () => {
        mountOnTile();
        openTilePanel();

        typeTileQuery('Example Trading');
        jest.advanceTimersByTime(400);

        expect(ajax.calls).toHaveLength(0);
        expect(resultTexts()).toContain('Select your country above to search for your company.');
    });

    /** A malformed payload must read as "no country", not go on the wire -
     * junk in `country` is a silently wrong register, not a visible error. */
    test.each([['', 'empty'], ['GBR', 'three letters'], ['1', 'a digit'], ['  ', 'whitespace']])(
        'a billing_country of %p (%s) is treated as absent',
        (value) => {
            window.twopayment = { billing_country: value };
            mountOnTile();
            openTilePanel();

            typeTileQuery('Example Trading');
            jest.advanceTimersByTime(400);

            expect(ajax.calls).toHaveLength(0);
        }
    );

    /** A buyer mid-edit has a country selected that no address carries yet -
     * a live select must outrank the server-resolved billing country. */
    test('a live country select still outranks the server-resolved billing country', () => {
        window.twopayment = { billing_country: 'GB' };
        document.body.insertAdjacentHTML(
            'afterbegin',
            "<select name='id_country'><option value='9' data-iso-code='NO' selected>Norway</option></select>"
        );
        mountOnTile();
        openTilePanel();

        typeTileQuery('Example Trading');
        jest.advanceTimersByTime(400);

        expect(ajax.calls).toHaveLength(1);
        expect(ajax.last().url).toContain('country=NO');
        expect(ajax.last().url).not.toContain('country=GB');
    });
});

describe('Bug 1: the control is the same width as the tile\'s other fields', () => {
    /**
     * Asserted against the optional-field block, not a hardcoded value: both
     * blocks are direct children of `.two-payment-container` with
     * `width: 100%` inputs, so equal insets means equal rendered width -
     * which jsdom (no layout) can't assert directly.
     */
    test('its horizontal inset equals the optional-fields block\'s', () => {
        installStylesheet('views/css/two.css');
        const tile = window.getComputedStyle(document.getElementById('two-tile-company-search'));
        const optional = window.getComputedStyle(document.getElementById('two-optional-fields'));

        expect(tile.paddingLeft).toBe(optional.paddingLeft);
        expect(tile.paddingRight).toBe(optional.paddingRight);
        // Not vacuous: 0 on both would satisfy the equality while reproducing the bug.
        expect(parseFloat(tile.paddingLeft)).toBeGreaterThan(0);
    });
});

describe('Bug 2: no "go back to your billing address" prompt in tile mode', () => {
    let TwoCheckoutManager;

    /** The manager's own `init()` runs on construction against the
     * tile-only DOM built above, matching the page the buyer is on. */
    beforeEach(() => {
        loadScript('views/js/modules/TwoCheckoutManager.js');
        TwoCheckoutManager = window.TwoCheckoutManager;
    });

    function manager(inAddressArea) {
        return new TwoCheckoutManager({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: inAddressArea
        });
    }

    function infoSection() {
        return document.querySelector('.two-payment-info');
    }

    /** Both statuses render a "go to the address step" sentence, wrong in
     * this mode since the search is right there in the tile. */
    test.each(['no_company', 'incomplete_company'])(
        'showCompanyRequiredMessage(%s) renders nothing',
        (status) => {
            manager(false).showCompanyRequiredMessage('', status);

            expect(shown(infoSection())).toBe(false);
            expect(infoSection().querySelector('.two-payment-message').textContent).toBe('');
        }
    );

    test('a server-supplied prompt is suppressed too, not just our own copy', () => {
        manager(false).showCompanyRequiredMessage(
            'To pay with Two, go back to your billing address and search for your company name.',
            'incomplete_company'
        );

        expect(shown(infoSection())).toBe(false);
        expect(document.body.textContent).not.toContain('go back to your billing address');
    });

    test('the order-intent error path suppresses it when the company is missing', () => {
        manager(false).showOrderIntentError('Organization number is required');

        expect(shown(infoSection())).toBe(false);
    });

    /**
     * Suppression is scoped to the prompt, not error reporting: a real
     * failure with a company already selected still has to reach the buyer.
     */
    test('a genuine error with a company selected is still shown', () => {
        // `companyid` is the only field isCompanyDataMissing() reads (not a
        // `two_company_id` cookie - nothing in the module writes one).
        let orgField = document.querySelector("input[name='companyid']");
        if (!orgField) {
            orgField = document.createElement('input');
            orgField.type = 'hidden';
            orgField.name = 'companyid';
            document.body.appendChild(orgField);
        }
        orgField.value = '11111111';
        // Marker must match the company field, or the search control clears
        // it on mount as a stale re-render leftover.
        orgField.setAttribute('data-two-company-name', 'Example Trading Ltd');
        document.querySelector('#two_tile_company').value = 'Example Trading Ltd';

        manager(false).showOrderIntentError('Something went wrong upstream');

        expect(shown(infoSection())).toBe(true);
        expect(infoSection().querySelector('.two-payment-message').textContent).not.toBe('');
    });

    /** In address-area mode the prompt is CORRECT and must still render -
     * without this the fix could pass by never prompting at all. */
    test('address-area mode still shows the prompt', () => {
        manager(true).showCompanyRequiredMessage('', 'incomplete_company');

        expect(shown(infoSection())).toBe(true);
        expect(infoSection().querySelector('.two-payment-message').textContent).not.toBe('');
    });

    /** A prompt already on screen from an earlier check has to be taken
     * down, not merely not re-rendered. */
    test('a prompt already on screen is cleared rather than left behind', () => {
        const section = infoSection();
        section.style.display = 'block';
        section.classList.add('show', 'action-required');
        section.querySelector('.two-payment-message').textContent =
            'To pay with Two, go back to your billing address and search for your company name.';

        manager(false).showCompanyRequiredMessage('', 'no_company');

        expect(shown(section)).toBe(false);
        expect(section.querySelector('.two-payment-message').textContent).toBe('');
        expect(section.classList.contains('show')).toBe(false);
    });
});

/**
 * TWO-25503: the manual-entry gate reads this flag off the search instance, so
 * the manager has to hand it down at both mount points.
 */
describe('the manager hands companySearchInAddressArea down to the search control', () => {
    let TwoCheckoutManager;

    beforeEach(() => {
        loadScript('views/js/modules/TwoCheckoutManager.js');
        TwoCheckoutManager = window.TwoCheckoutManager;
    });

    test('the tile mount is constructed with it false', () => {
        const manager = new TwoCheckoutManager({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: false
        });
        manager.initializeCompanySearch();

        expect(manager.companySearch).toBeTruthy();
        expect(manager.companySearch.config.companySearchInAddressArea).toBe(false);
    });
});
