/**
 * ABN-554: what openPopup() puts in the hosted signup's `autofillData`.
 *
 * The hosted page reads `email`, `first_name`, `last_name`, `phone_number`,
 * `company_name`, a TOP-LEVEL `country_code`, and
 * `billing_address.{street,postal_code,city,region}`. Anything it is not sent
 * the buyer retypes by hand.
 */

'use strict';

const { loadSoleTrader } = require('./ps-harness');

let TwoSoleTrader;

function buildForm(options) {
    const opts = options || {};
    const stateSelect = opts.states
        ? [
            "    <select name='id_state'>",
            '      <option value="">-</option>',
            opts.states.map(function (state) {
                const selected = state.value === opts.stateValue ? ' selected' : '';
                return '      <option value="' + state.value + '"' + selected + '>' + state.text + '</option>';
            }).join('\n'),
            '    </select>'
        ].join('\n')
        : '';
    document.body.innerHTML = [
        '<div class="js-address-form">',
        '  <form data-id-address="7">',
        "    <input type='text' name='firstname' value='" + (opts.firstname || '') + "' />",
        "    <input type='text' name='lastname' value='" + (opts.lastname || '') + "' />",
        "    <input type='text' name='phone' value='" + (opts.phone || '') + "' />",
        "    <input type='text' name='company' value='' />",
        "    <input type='text' name='address1' value='" + (opts.address1 || '') + "' />",
        "    <input type='text' name='postcode' value='" + (opts.postcode || '') + "' />",
        "    <input type='text' name='city' value='" + (opts.city || '') + "' />",
        "    <select name='id_country'>",
        '      <option value="17" data-iso-code="' + ('country' in opts ? opts.country : 'GB') + '" selected>Country</option>',
        '    </select>',
        stateSelect,
        '  </form>',
        '</div>'
    ].join('\n');
}

function decodePrefill(url) {
    const encoded = new URL(String(url)).searchParams.get('autofillData');

    return JSON.parse(decodeURIComponent(escape(window.atob(encoded))));
}

/**
 * Given: a filled checkout form. When: the sole-trader popup opens.
 * Then: `autofillData` carries the field under test.
 */
function openAndDecode(options) {
    const opts = options || {};
    buildForm(opts.form);
    if (opts.selectedCompany === undefined) {
        delete window.TwoCheckoutManager_Instance;
    } else {
        window.TwoCheckoutManager_Instance = {
            getSelectedCompany: function () {
                return opts.selectedCompany;
            }
        };
    }
    const openSpy = jest.fn(function () {
        return { closed: false, focus: function () {} };
    });
    window.open = openSpy;
    const instance = new TwoSoleTrader(Object.assign({
        checkoutHost: 'https://api.example.test',
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        billingCountry: 'GB'
    }, opts.config || {}));
    instance.tokens = {
        signup_url: 'https://signup.example.test/soletrader/signup',
        delegation_token: 'del-token',
        autofill_token: 'af-token',
        country: 'NO'
    };
    instance.openPopup();
    const prefill = decodePrefill(openSpy.mock.calls[0][0]);
    instance.destroy();

    return prefill;
}

beforeEach(() => {
    // The constructor kicks off an availability refresh; this suite only cares
    // about what openPopup() builds from tokens it is handed directly.
    window.fetch = () => Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
    global.fetch = window.fetch;
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    delete global.fetch;
    delete window.fetch;
    delete window.TwoCheckoutManager_Instance;
    delete window.open;
    delete window.twopayment;
    document.body.innerHTML = '';
    window.localStorage.clear();
});

describe('sole-trader signup prefill', () => {
    test.each([
        {
            options: { form: { address1: '12 Mill Lane' } },
            read: prefill => prefill.billing_address.street,
            expected: '12 Mill Lane',
            description: 'street comes from address1'
        },
        {
            options: { form: { postcode: 'SW1A 1AA' } },
            read: prefill => prefill.billing_address.postal_code,
            expected: 'SW1A 1AA',
            description: 'postal_code comes from postcode'
        },
        {
            options: { form: { city: 'Ashford' } },
            read: prefill => prefill.billing_address.city,
            expected: 'Ashford',
            description: 'city comes from city'
        },
        {
            options: {
                form: { country: 'US', states: [{ value: '5', text: 'California' }], stateValue: '5' }
            },
            read: prefill => prefill.billing_address.region,
            expected: 'California',
            description: 'region is the selected state NAME, not its shop-local id'
        },
        {
            options: { form: { country: 'US', states: [{ value: '5', text: 'California' }] } },
            read: prefill => prefill.billing_address.region,
            expected: '',
            description: 'the state placeholder row is not a region'
        },
        {
            options: { form: {} },
            read: prefill => prefill.billing_address.region,
            expected: '',
            description: 'a country with no state select carries no region'
        },
        {
            options: { selectedCompany: { name: 'Mill Lane Joinery', number: '' } },
            read: prefill => prefill.company_name,
            expected: 'Mill Lane Joinery',
            description: 'company_name is the captured company'
        },
        {
            options: {},
            read: prefill => prefill.company_name,
            expected: '',
            description: 'no capture yet means an empty company_name, never a half-typed query'
        },
        {
            options: { form: { country: 'NL' }, config: { billingCountry: 'GB' } },
            read: prefill => prefill.country_code,
            expected: 'NL',
            description: 'country_code follows the live selector, not the server-rendered fallback'
        },
        {
            options: { form: { country: '' }, config: { billingCountry: 'SE' } },
            read: prefill => prefill.country_code,
            expected: 'SE',
            description: 'country_code falls back to the cart billing country when the selector resolves nothing'
        }
    ])('$description', ({ options, read, expected }) => {
        expect(read(openAndDecode(options))).toBe(expected);
    });

    test('the buyer identity fields are still carried', () => {
        const prefill = openAndDecode({
            form: { firstname: 'Test', lastname: 'Buyer', phone: '+442071234567' }
        });
        expect(prefill.first_name).toBe('Test');
        expect(prefill.last_name).toBe('Buyer');
        expect(prefill.phone_number).toBe('+442071234567');
    });
});
