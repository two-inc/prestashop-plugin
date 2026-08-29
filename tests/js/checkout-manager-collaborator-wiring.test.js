/**
 * The collaborator wiring `TwoCheckoutManager` injects into the instances it
 * builds - `reopenMemory`, `getManager`, `getCompanySearch`.
 *
 * This is what replaced class-static sharing when the control became
 * instantiable, and it is only observable at the manager level: every one of
 * these has a fallback (a fresh scratch object, the `window` singletons, a
 * document-wide address read) that answers plausibly when the injection is
 * absent. So each test below is built so the fallback's answer is a DIFFERENT
 * value from the injected one.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    stubAjax,
    buildAddressesStep
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

// The block the control mounts in, and the one a document-wide read lands on.
const MOUNTED_BLOCK_ADDRESS_ID = 9;
const DOCUMENT_FIRST_ADDRESS_ID = 7;

let TwoCheckoutManager;
let TwoOrderIntent;
let $;
let ajax;

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    ajax = stubAjax($);
    loadOrderIntent();
    TwoOrderIntent = window.TwoOrderIntent;
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;
    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST
    };
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    if (window.TwoCheckoutManager_Instance) {
        delete window.TwoCheckoutManager_Instance;
    }
    document.body.innerHTML = '';
    document.body.className = '';
    delete window.twopayment;
});

/**
 * @param {Object} [extraConfig]
 * @returns {Object} a manager mounting the search in the address area
 */
function makeManager(extraConfig) {
    return new TwoCheckoutManager(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token',
        companySearchInAddressArea: true
    }, extraConfig || {}));
}

describe('the reopen memory the manager injects', () => {
    test('a deadline armed before a rebuild is still owed by the replacement', () => {
        // Given a mounted control that owes the buyer a reopen
        buildAddressesStep({ editing: 'invoice' });
        const manager = makeManager();
        const deadline = Date.now() + 60000;
        manager.companySearch.armReopen(deadline);
        const before = manager.companySearch;

        // When `updatedAddressForm` destroys it and builds a replacement
        manager.handleAddressFormUpdate();

        // Then the replacement reads the SAME scratch, not a fresh one
        expect(manager.companySearch).not.toBe(before);
        expect(manager.companySearch.reopenDeadline()).toBe(deadline);
    });
});

describe('the manager accessor the manager injects', () => {
    test('the control it built answers with IT, not with the window singleton', () => {
        // Given a control built by one manager, and a different manager published
        // on `window` - which is what the fallback reads
        buildAddressesStep({ editing: 'invoice' });
        const manager = makeManager();
        const other = { marker: 'window singleton' };
        window.TwoCheckoutManager_Instance = other;

        // Then the control answers with its builder
        expect(manager.companySearch.manager()).toBe(manager);
    });
});

describe('the company-search accessor the manager injects', () => {
    /**
     * Two address blocks whose ids differ, where the block the control mounts in
     * is NOT the one a document-wide read answers with: the first block carries
     * the editable-form marker but no company input, so the control mounts in the
     * second.
     *
     * @returns {void}
     */
    function buildBlocksWhereScopeDisagreesWithDocument() {
        document.body.innerHTML = [
            '<div id="delivery-address">',
            '  <div class="js-address-form">',
            '    <form data-id-address="' + DOCUMENT_FIRST_ADDRESS_ID + '">',
            "      <input type='hidden' name='saveAddress' value='delivery' />",
            '    </form>',
            '  </div>',
            '</div>',
            '<div id="invoice-address">',
            '  <div class="js-address-form">',
            '    <form data-id-address="' + MOUNTED_BLOCK_ADDRESS_ID + '">',
            "      <input type='text' name='company' value='' />",
            "      <input type='text' name='address1' value='' />",
            "      <input type='text' name='postcode' value='' />",
            "      <input type='text' name='city' value='' />",
            '      <select name="id_country">',
            '        <option value="17" data-iso-code="GB" selected>GB</option>',
            '      </select>',
            "      <input type='hidden' name='saveAddress' value='invoice' />",
            '    </form>',
            '  </div>',
            '</div>'
        ].join('\n');
    }

    test('order intent answers with the mounted control block, not the document-wide one', () => {
        // Given the two disagree
        buildBlocksWhereScopeDisagreesWithDocument();
        const manager = makeManager();
        manager.initializeOrderIntent();

        expect(manager.companySearch.getCurrentAddressId()).toBe(MOUNTED_BLOCK_ADDRESS_ID);
        expect(new TwoOrderIntent({}).getCurrentAddressId()).toBe(DOCUMENT_FIRST_ADDRESS_ID);

        // Then the manager-wired intent delegates to the control
        expect(manager.orderIntent.getCurrentAddressId()).toBe(MOUNTED_BLOCK_ADDRESS_ID);
    });

    test('it is read live, so a rebuilt control is the one that answers', () => {
        // Given an intent built once against a control that is later replaced
        buildBlocksWhereScopeDisagreesWithDocument();
        const manager = makeManager();
        manager.initializeOrderIntent();
        const before = manager.companySearch;

        // When `updatedAddressForm` rebuilds the control
        manager.handleAddressFormUpdate();

        // Then the intent is answering through the replacement
        expect(manager.companySearch).not.toBe(before);
        expect(manager.orderIntent.getCurrentAddressId()).toBe(MOUNTED_BLOCK_ADDRESS_ID);
    });
});
