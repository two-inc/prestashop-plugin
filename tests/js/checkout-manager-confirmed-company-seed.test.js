/**
 * TWO-40 #13. Seeding the page-lifetime confirmed selection from the server's
 * cart-scoped record.
 *
 * `TwoCheckoutManager._confirmedCompanySelection` is page-lifetime only, and
 * PrestaShop's address step is a sequence of real document loads - the buyer
 * states their invoice address differs by following a link, which navigates. So
 * on the page where the invoice form finally appears, every consumer of the
 * confirmed selection is looking at null: the invoice-form mirror has nothing to
 * mirror, and the intent check falls back to a round trip.
 *
 * The server publishes the record it holds for the current cart, already through
 * its validated read, and the manager adopts it at construction. These specs pin
 * that adoption, and specifically that it carries the CAPTURED address and
 * country through rather than re-deriving them from the page it is being restored
 * onto - which would stamp the selection with values that defeat the very
 * invalidation checks it must stay subject to.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    stubAjax,
    buildAddressesStep,
    buildPaymentTile,
    rebuildAddressesStepAsCoreDoes
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let TwoCheckoutManager;
let $;
let ajax;
let bus;

const SERVER_RECORD = {
    company: 'Acme Trading Ltd',
    companyid: '12345678',
    country: 'GB',
    address_id: 7
};

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    bus = loaded.bus;
    ajax = stubAjax($);
    loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;
    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST
    };
    document.body.innerHTML = '<div class="payment-options"></div>';
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

function makeManager(extraConfig) {
    return new TwoCheckoutManager(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token'
    }, extraConfig || {}));
}

describe('adopting the server record', () => {
    test('a published record becomes the confirmed selection', () => {
        const manager = makeManager({ confirmedCompany: SERVER_RECORD });

        expect(manager.getConfirmedCompanySelection()).toEqual({
            company: 'Acme Trading Ltd',
            companyid: '12345678',
            addressId: 7,
            countryIso: 'GB'
        });
    });

    test('the captured address and country come from the record, not from this page', () => {
        // A page whose own address and country differ from the ones the selection
        // was captured against. Re-deriving them here would silently make the
        // record look current and neuter both invalidation checks.
        document.body.innerHTML = [
            '<div class="payment-options"></div>',
            "<input type='hidden' name='id_address_invoice' value='99' />",
            "<select name='id_country'><option value='8' data-iso-code='FR' selected>France</option></select>"
        ].join('\n');

        const selection = makeManager({ confirmedCompany: SERVER_RECORD }).getConfirmedCompanySelection();

        expect(selection.addressId).toBe(7);
        expect(selection.countryIso).toBe('GB');
    });

    test('nothing is adopted when the server published nothing', () => {
        expect(makeManager().getConfirmedCompanySelection()).toBeNull();
        expect(makeManager({ confirmedCompany: null }).getConfirmedCompanySelection()).toBeNull();
    });

    test('a record missing either half of the pair is not adopted', () => {
        expect(
            makeManager({ confirmedCompany: { company: 'Acme Trading Ltd', companyid: '' } })
                .getConfirmedCompanySelection()
        ).toBeNull();
        expect(
            makeManager({ confirmedCompany: { company: '', companyid: '12345678' } })
                .getConfirmedCompanySelection()
        ).toBeNull();
    });

    test('an unknown captured address or country degrades to "unknown", not to this page\'s value', () => {
        const selection = makeManager({
            confirmedCompany: { company: 'Acme Trading Ltd', companyid: '12345678' }
        }).getConfirmedCompanySelection();

        expect(selection.addressId).toBe(0);
        expect(selection.countryIso).toBe('');
    });

    test('an in-page selection is never overwritten by the seed', () => {
        const manager = makeManager({ confirmedCompany: SERVER_RECORD });

        manager.setConfirmedCompanySelection({ company: 'Beta Holdings AS', companyid: '99887766' });
        manager.seedConfirmedCompanySelectionFromServer();

        expect(manager.getConfirmedCompanySelection().company).toBe('Beta Holdings AS');
    });
});

describe('the config mapping the server payload goes through', () => {
    test('carries the published record onto the manager config', () => {
        loadScript('views/js/twopayment.js');

        const mapped = window.twoBuildCheckoutManagerConfig({
            checkout_host: CHECKOUT_HOST,
            confirmed_company: SERVER_RECORD
        });

        expect(mapped.confirmedCompany).toEqual(SERVER_RECORD);
    });

    test('reads an absent or null key as no record rather than as a truthy object', () => {
        loadScript('views/js/twopayment.js');

        expect(window.twoBuildCheckoutManagerConfig({}).confirmedCompany).toBeNull();
        expect(
            window.twoBuildCheckoutManagerConfig({ confirmed_company: null }).confirmedCompany
        ).toBeNull();
    });
});

/**
 * TWO-40, adversarial review round 5, B3.
 *
 * `isCompanyDataMissing()` read ONLY the hidden `companyid` input. Seeding the
 * page-lifetime holder from the server's cart-scoped record added a second
 * legitimate place a real selection lives while that input is empty - or, on the
 * payment step, absent from the document altogether, because PrestaShop has
 * removed the address form by then.
 *
 * So a genuine order-intent failure (a 500, a timeout) with a seeded selection was
 * classified as "the buyer never picked a company". In tile mode that branch is
 * suppressed outright - the tile's own search control is what prompts, so there is
 * nothing to render - and the buyer was therefore shown NOTHING for a real
 * failure.
 */
describe('a real failure is not misreported as "you did not pick a company"', () => {
    const GENERIC = 'There was an issue processing your Two payment request';
    const RELOCATION_PROMPT = 'go back to your billing address';

    /**
     * @param {Object} [extraConfig]
     * @returns {Object} a manager on the payment step with the shipped tile
     *          rendered and company search relocated into it (tile mode)
     */
    function tileModeManager(extraConfig) {
        buildPaymentTile();

        return makeManager(Object.assign({
            companySearchInAddressArea: false
        }, extraConfig || {}));
    }

    function messagePanel() {
        return document.querySelector('.two-payment-info');
    }

    test('a seeded selection IS company data, with no companyid input in the document at all', () => {
        const manager = tileModeManager({ confirmedCompany: SERVER_RECORD });

        expect(document.querySelector("input[name='companyid']")).toBeNull();
        expect(manager.isCompanyDataMissing()).toBe(false);
    });

    test('in tile mode the failure surfaces an error rather than nothing', () => {
        // THE REPRO. Before the fix this call returned early inside
        // suppressCompanyRelocationPrompt() and the panel stayed hidden and empty.
        const manager = tileModeManager({ confirmedCompany: SERVER_RECORD });

        manager.showOrderIntentError('Request failed with status 500');

        expect(messagePanel().style.display).toBe('block');
        expect(messagePanel().textContent).toContain(GENERIC);
    });

    test('the address-area mode gets the generic error too, not the relocation prompt', () => {
        buildPaymentTile();
        const manager = makeManager({
            confirmedCompany: SERVER_RECORD,
            companySearchInAddressArea: true
        });

        manager.showOrderIntentError('Request failed with status 500');

        expect(messagePanel().textContent).toContain(GENERIC);
        expect(messagePanel().textContent).not.toContain(RELOCATION_PROMPT);
    });

    test('with no selection anywhere, the company branch is unchanged', () => {
        // The classification this fix must NOT weaken: nothing in the DOM and
        // nothing seeded really is "no company selected", and tile mode really does
        // stay silent about it because the tile's own search control prompts.
        const manager = tileModeManager();

        expect(manager.isCompanyDataMissing()).toBe(true);

        manager.showOrderIntentError('Request failed with status 500');

        expect(messagePanel().style.display).toBe('none');
        expect(messagePanel().textContent).not.toContain(GENERIC);
    });

    test('and in address-area mode still prompts the buyer to go and pick one', () => {
        buildPaymentTile();
        const manager = makeManager({ companySearchInAddressArea: true });

        manager.showOrderIntentError('Request failed with status 500');

        expect(messagePanel().textContent).toContain(RELOCATION_PROMPT);
    });
});

/**
 * The mirror's page-lifetime memory belongs to the MANAGER, not to the search:
 * the manager destroys and rebuilds the search on every `updatedAddressForm`, so
 * a memory kept on the search would be gone at exactly the moment it is needed.
 *
 * These two specs drive that through the real wiring - a real manager, core's own
 * markup, core's own rebuild - rather than asserting on the config object, so the
 * injection cannot rot into passing a fresh object each time.
 */
describe('the invoice mirror survives the search being rebuilt', () => {
    const MARKER = 'data-two-autofilled-value';

    function mountOnInvoiceStep() {
        buildAddressesStep({ editing: 'invoice' });
        // core's own body class for the checkout controller, with an address form
        // and no payment options on the page: the manager's step detection reads
        // that as the ADDRESS step, which is where the mirror belongs.
        document.body.className = 'controller-order';

        return makeManager({
            confirmedCompany: SERVER_RECORD,
            companySearchInAddressArea: true,
            addressLookupEnabled: true,
            countries: { 17: 'gb', 8: 'fr', 1: 'de' }
        });
    }

    test('a company the buyer cleared is not re-filled when the search is rebuilt', () => {
        mountOnInvoiceStep();
        const company = () => $("#invoice-address input[name='company']");

        expect(company().val()).toBe('Acme Trading Ltd');

        company().val('');
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: '8' });
        bus.emit('updatedAddressForm');

        expect(company().val()).toBe('');
    });

    test('the marker core stripped is re-established when the search is rebuilt', () => {
        mountOnInvoiceStep();
        const company = () => $("#invoice-address input[name='company']");

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: '8' });
        expect(company().val()).toBe('Acme Trading Ltd');
        expect(company().attr(MARKER)).toBeUndefined();

        bus.emit('updatedAddressForm');

        expect(company().attr(MARKER)).toBe('Acme Trading Ltd');
    });
});
