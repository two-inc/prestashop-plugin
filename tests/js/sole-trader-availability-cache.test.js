/**
 * TWO-40 follow-up: two bugs, one fix each.
 *
 * Bug 1 (live on staging, Doug's report): the "I'm a sole trader" row never
 * appeared on the address-editor page, even for a registry-supported country
 * (GB). Root cause - refreshAvailability() early-returned whenever
 * `.two-sole-trader` was absent from the page, BEFORE it ever resolved the
 * billing country or fired the fetch. That container only ever exists on the
 * payment step (rendered by paymentinfo.tpl); nothing renders it on the
 * address-editor page at all. So availability never resolved for ANY
 * country on any page other than the payment step, however eligible the
 * country was. See TwoSoleTrader.js's refreshAvailability() for the fix and
 * its full reasoning.
 *
 * Bug 2 (Doug's own request): even with bug 1 fixed, every fresh page load
 * re-fires the availability round trip before the chip can appear, because
 * `availabilityByCountry` is in-memory only and resets on every navigation.
 * A localStorage cache, keyed per ISO country and namespaced per checkout
 * environment (see availabilityStorageKey()'s doc), with a 24h TTL, lets a
 * later page paint from cache with no round trip at all.
 */

'use strict';

const { loadSoleTrader, flushPromises } = require('./ps-harness');

let TwoSoleTrader;
let fetchCalls;
let answer;

const CHECKOUT_HOST = 'https://checkout.staging.two.inc';

/** Same shape as the identical helper in sole-trader-toggle-flicker.test.js. */
function buildCountry(iso) {
    const holder = document.createElement('div');
    holder.innerHTML = "<select name='id_country'>"
        + '<option value="17" data-iso-code="' + iso + '" selected>Country</option>'
        + '</select>';
    document.body.appendChild(holder);
    return holder.querySelector('select');
}

function build(overrides) {
    return new TwoSoleTrader(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        shopCountry: 'ZZ',
        billingCountry: 'GB'
    }, overrides || {}));
}

function storageKey(country) {
    return 'two_sole_trader_availability::' + CHECKOUT_HOST + '::' + country;
}

function seedCache(country, available, ageMs) {
    window.localStorage.setItem(storageKey(country), JSON.stringify({
        available: available,
        ts: Date.now() - ageMs
    }));
}

/** Let the debounced observer callback and any promise chain run. */
async function drain() {
    jest.advanceTimersByTime(150);
    await flushPromises();
    jest.advanceTimersByTime(150);
    await flushPromises();
}

beforeEach(() => {
    jest.useFakeTimers();
    delete global.window.TwoSoleTrader;
    fetchCalls = [];
    answer = { available: true, reject: false };
    global.window.fetch = (url) => {
        fetchCalls.push(url);
        if (answer.reject) {
            return Promise.reject(new Error('network'));
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true, available: answer.available }) });
    };
    global.fetch = global.window.fetch;
});

afterEach(() => {
    if (global.window.TwoSoleTrader_Instance) {
        delete global.window.TwoSoleTrader_Instance;
    }
    jest.useRealTimers();
    document.body.innerHTML = '';
    delete global.window.fetch;
    delete global.fetch;
    global.window.localStorage.clear();
});

describe('availability resolves with no .two-sole-trader container on the page (address-editor bug)', () => {
    test('a registry-supported country resolves true via the network fetch', async () => {
        // No buildPaymentTile()/buildPaymentTileWithSoleTraderAnswer() call at
        // all - the address-editor page, unlike the payment step, never
        // renders `.two-sole-trader`. Before the fix, refreshAvailability()
        // returned immediately here and isAvailableForCurrentCountry() could
        // never become true, for GB or any other country.
        expect(document.querySelector('.two-sole-trader')).toBeNull();
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        await drain();

        expect(document.querySelector('.two-sole-trader')).toBeNull();
        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=GB');
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.destroy();
    });

    test('a business-only country resolves false, still with no container anywhere', async () => {
        answer.available = false;
        buildCountry('NO');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: 'NO' });

        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        instance.destroy();
    });

    test('a country change with no container still re-resolves, not just the initial load', async () => {
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();
        expect(fetchCalls).toHaveLength(1);

        answer.available = false;
        select.querySelector('option').setAttribute('data-iso-code', 'SE');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await drain();

        expect(fetchCalls).toHaveLength(2);
        expect(fetchCalls[1]).toContain('country=SE');
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        instance.destroy();
    });

    test('apply() degrades gracefully with no container - no throw, no enrolment UI', async () => {
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        expect(() => instance.startEnrollment()).not.toThrow();
        expect(() => instance.showPrompt()).not.toThrow();
        expect(() => instance.hidePrompt()).not.toThrow();
        expect(() => instance.showStatus('x')).not.toThrow();
        expect(() => instance.showError()).not.toThrow();

        await drain();
        instance.destroy();
    });
});

describe('a fresh, valid localStorage cache entry is used with no fetch at all', () => {
    test('a cache hit populates availabilityByCountry and answers correctly, before any network call', async () => {
        seedCache('GB', true, 60 * 1000); // 1 minute old
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        // Synchronous, on construction - init() -> refreshAvailability() runs
        // in the constructor, so this must already be true before any promise
        // or timer has had a chance to run. That is the entire point of the
        // cache: no round trip stands between page load and the chip showing.
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        expect(instance.availabilityByCountry.GB).toBe(true);
        expect(fetchCalls).toEqual([]);

        await drain();
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });

    test('a cached "false" answer is honoured just as readily as "true"', async () => {
        seedCache('NO', false, 60 * 1000);
        buildCountry('NO');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: 'NO' });

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });

    test('a cache hit for one country does not suppress a fetch for a different one', async () => {
        seedCache('GB', true, 60 * 1000);
        buildCountry('NO');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: 'NO' });

        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=NO');
        instance.destroy();
    });
});

describe('a stale (>24h) cache entry is not used', () => {
    test('an entry older than the 24h TTL is ignored and a fresh fetch is issued', async () => {
        seedCache('GB', true, 24 * 60 * 60 * 1000 + 1000); // just over 24h
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        // Not yet resolved synchronously - the stale entry must not be trusted.
        expect(instance.availabilityByCountry.GB).toBeUndefined();

        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=GB');
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.destroy();
    });

    test('an entry exactly at the boundary (not yet expired) is still honoured', async () => {
        seedCache('GB', true, 24 * 60 * 60 * 1000 - 1000); // just under 24h
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });

    test('a malformed cache entry (bad JSON) is treated as a miss, not an error', async () => {
        window.localStorage.setItem(storageKey('GB'), 'not-json{');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        expect(() => instance.isAvailableForCurrentCountry()).not.toThrow();
        await drain();

        expect(fetchCalls).toHaveLength(1);
        instance.destroy();
    });

    test('a cache entry missing the expected shape (no `ts`) is treated as a miss', async () => {
        window.localStorage.setItem(storageKey('GB'), JSON.stringify({ available: true }));
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        await drain();

        expect(fetchCalls).toHaveLength(1);
        instance.destroy();
    });

    test('a FUTURE `ts` (skewed clock, or planted by another script) is rejected, not treated as fresher-than-fresh', async () => {
        // Adversarial review finding: `Date.now() - parsed.ts` going negative
        // must not read as "not yet expired" - that would pin this answer
        // for the country indefinitely, however long it actually sat there.
        seedCache('GB', true, -60 * 1000); // ts is 1 minute in the FUTURE
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        expect(instance.availabilityByCountry.GB).toBeUndefined();

        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.destroy();
    });
});

describe('the cache is a no-op, not a shared key, when checkoutHost is unknown', () => {
    test('no cache read/write happens when config.checkoutHost is empty', async () => {
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        // No `checkoutHost` at all - a real page always has one
        // (window.twopayment.checkout_host), but this must degrade safely
        // rather than fall back to a shared '' namespace segment that a
        // DIFFERENT, also-unidentified environment could also fall into.
        const instance = build({ checkoutHost: '' });

        await drain();
        expect(fetchCalls).toHaveLength(1);
        // The successful answer must not have been written anywhere
        // guessable - confirm no entry exists under the "collapsed" key an
        // unguarded fallback would have used.
        expect(window.localStorage.getItem('two_sole_trader_availability::::GB')).toBeNull();
        instance.destroy();
    });

    test('a pre-existing entry under the collapsed "::" key is never read back', async () => {
        // Simulates an entry a pre-fix build (or another unidentified
        // environment) could have left behind under the shared fallback key.
        window.localStorage.setItem(
            'two_sole_trader_availability::::GB',
            JSON.stringify({ available: false, ts: Date.now() })
        );
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ checkoutHost: '' });

        await drain();

        // Must still hit the network - a stale/foreign answer under the
        // collapsed key must never be adopted as this environment's own.
        expect(fetchCalls).toHaveLength(1);
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.destroy();
    });
});

describe('a transport failure is never persisted to the cache', () => {
    test('a rejected fetch leaves no localStorage entry behind', async () => {
        answer.reject = true;
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        await drain();

        expect(fetchCalls).toHaveLength(1);
        // Fail-soft in memory, as before...
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        // ...but NOT written to the persistent cache - a transport blip must
        // not become a day-long false "not available" for this country.
        expect(window.localStorage.getItem(storageKey('GB'))).toBeNull();
        instance.destroy();
    });

    test('a later successful load after a transport failure resolves normally (no permanent poisoning)', async () => {
        answer.reject = true;
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        instance.destroy();

        // A fresh instance, as a later page load would construct - the earlier
        // failure must not have left a cached "false" behind for it to adopt.
        answer.reject = false;
        answer.available = true;
        delete global.window.TwoSoleTrader;
        TwoSoleTrader = loadSoleTrader();
        const second = build();
        await drain();

        expect(fetchCalls).toHaveLength(2);
        expect(second.isAvailableForCurrentCountry()).toBe(true);
        expect(window.localStorage.getItem(storageKey('GB'))).not.toBeNull();
        second.destroy();
    });

    test('a genuine "success: false" server answer (not a transport failure) IS persisted as false', async () => {
        // Distinct from the catch()/reject path: the fetch resolved, the
        // server responded, it just said "not available" (or malformed
        // success). That is a real answer, not a blip - see the comment in
        // TwoSoleTrader.js's refreshAvailability() fetch handler.
        global.window.fetch = (url) => {
            fetchCalls.push(url);
            return Promise.resolve({ json: () => Promise.resolve({ success: false }) });
        };
        global.fetch = global.window.fetch;
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        expect(window.localStorage.getItem(storageKey('GB'))).not.toBeNull();
        const cached = JSON.parse(window.localStorage.getItem(storageKey('GB')));
        expect(cached.available).toBe(false);
        instance.destroy();
    });
});

describe('a server-rendered adoption also persists to the cache', () => {
    test('adoptServerRenderedToggle() writes the answer, so a later container-less page can read it', async () => {
        const { buildPaymentTileWithSoleTraderAnswer } = require('./ps-harness');
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        expect(window.localStorage.getItem(storageKey('GB'))).not.toBeNull();
        const cached = JSON.parse(window.localStorage.getItem(storageKey('GB')));
        expect(cached.available).toBe(true);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });

    test('a matching re-adoption does NOT rewrite the cache entry (adversarial review, "Han" finding, round 2)', async () => {
        // Round 1 fixed adoptServerRenderedToggle() writing to localStorage on
        // EVERY container swap, even an unchanged answer - the file's own
        // comments say PrestaShop swaps `.two-sole-trader` "constantly" while
        // a checkout step settles, undebounced. Round 2 review noted nothing
        // actually pinned that skip: this proves a same-value re-adoption
        // leaves the stored `ts` untouched rather than rewriting it.
        const { buildPaymentTileWithSoleTraderAnswer } = require('./ps-harness');
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        const firstWrite = window.localStorage.getItem(storageKey('GB'));
        expect(firstWrite).not.toBeNull();

        // Re-adopt the SAME answer directly - same shape as the observer's
        // adoptReplacedContainer() -> adoptServerRenderedToggle() path,
        // without needing to fake a real DOM swap.
        instance.adoptServerRenderedToggle();
        instance.adoptServerRenderedToggle();

        const secondWrite = window.localStorage.getItem(storageKey('GB'));
        // Byte-identical: not just "still available: true" but the exact
        // same `ts`, proving no redundant setItem() actually ran.
        expect(secondWrite).toBe(firstWrite);
        instance.destroy();
    });
});

describe('the cache is namespaced per checkout environment, not shared across them', () => {
    test('an answer cached for one checkoutHost is not read back for a different one', async () => {
        seedCache('GB', true, 60 * 1000); // seeded under CHECKOUT_HOST (staging)
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        // A DIFFERENT environment - e.g. a shared browser profile that also has
        // a production shop open.
        const instance = build({ checkoutHost: 'https://checkout.two.inc' });

        // Must not have adopted the staging-namespaced cache entry.
        expect(instance.availabilityByCountry.GB).toBeUndefined();

        await drain();
        expect(fetchCalls).toHaveLength(1);
        instance.destroy();
    });
});

describe('the persisted cache never outranks a settled, container-present answer (adversarial review, "Han" finding)', () => {
    test('a fresh server-rendered adoption wins over a DISAGREEING persisted-cache entry', async () => {
        const { buildPaymentTileWithSoleTraderAnswer } = require('./ps-harness');
        // The persisted cache says "no" for GB...
        seedCache('GB', false, 60 * 1000);
        // ...but the server just rendered "yes" into the markup this load.
        // adoptServerRenderedToggle() runs in the constructor, before
        // refreshAvailability() ever gets to the persisted-cache read at
        // all - the settled-container check short-circuits first.
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        expect(fetchCalls).toEqual([]);

        await drain();

        // Still true - the disagreeing cache entry never won, and the fresh
        // answer overwrote it (see the "no redundant write" comment on
        // adoptServerRenderedToggle - it writes here because the value
        // actually changed).
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        const cached = JSON.parse(window.localStorage.getItem(storageKey('GB')));
        expect(cached.available).toBe(true);
        instance.destroy();
    });
});

describe('a superseded in-flight request is not resurrected by a concurrent cache write (adversarial review, "Han" finding)', () => {
    test("an outstanding request's answer, once superseded, never reaches the persisted cache either", async () => {
        // Mirrors sole-trader-server-rendered-toggle.test.js's in-memory
        // version of this invariant, but checks the PERSISTED cache too -
        // the brief specifically asked whether a concurrent cache write
        // could resurrect a request that lost the in-memory race.
        let settle;
        global.window.fetch = (url) => {
            fetchCalls.push(url);
            return new Promise((resolve) => { settle = resolve; });
        };
        global.fetch = global.window.fetch;

        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        jest.advanceTimersByTime(150);
        await flushPromises();
        expect(fetchCalls).toHaveLength(1);
        // Nothing settled yet - no container to adopt a server answer from,
        // and no fetch response either.
        expect(window.localStorage.getItem(storageKey('GB'))).toBeNull();

        // A container now appears carrying a FRESH server-rendered answer
        // (e.g. PrestaShop swapped in the payment fragment mid-request) -
        // this bumps `_adoptGeneration`, superseding the outstanding fetch.
        const { buildPaymentTileWithSoleTraderAnswer } = require('./ps-harness');
        buildPaymentTileWithSoleTraderAnswer('0', 'GB');
        await flushPromises();
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        const afterAdopt = JSON.parse(window.localStorage.getItem(storageKey('GB')));
        expect(afterAdopt.available).toBe(false);

        // The original request now resolves with the OPPOSITE answer. It
        // must not win in memory (pre-existing invariant) OR overwrite the
        // cache with its now-stale answer (the new invariant this test
        // pins).
        settle({ json: () => Promise.resolve({ success: true, available: true }) });
        await drain();

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        const afterSettle = JSON.parse(window.localStorage.getItem(storageKey('GB')));
        expect(afterSettle.available).toBe(false);
        instance.destroy();
    });
});
