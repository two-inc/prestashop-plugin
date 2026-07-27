<?php

declare(strict_types=1);

/**
 * TWO-24799 - checkout-path latency: the nested company lookup and the
 * order-intent snapshot dedupe. Contract under test:
 *
 *  - Nested company lookup. An org number found on a saved address is verified
 *    against /companies/v2/company on the buyer-blocking path. The SUCCESS path
 *    was already memoised (the caller writes the resolved company into the
 *    two_company_* cookie); the MISS path was not, so an unresolvable org number
 *    re-paid that blocking round trip on every checkout update, forever
 *    returning the same null. getTwoVerifiedCompanyForOrgNumber() memoises both,
 *    keyed on org number + country + address ID so editing any of the three
 *    re-verifies instead of inheriting the miss.
 *
 *  - Order-intent snapshot dedupe. Every checkout update re-runs the intent
 *    handler and the browser then pays a 2.5-3s /v1/order_intent round trip even
 *    when no decision input moved. calculateTwoOrderIntentSnapshotHash() keys a
 *    session-scoped decision cache on the full decision snapshot. It composes -
 *    never replaces - the cart snapshot hash used for order-create idempotency,
 *    mixing in the buyer identity that hash deliberately omits.
 *
 *  - Invalidation is the load-bearing property. A country switch, a cart change,
 *    a company change and an address switch must each bust the cache; a stale
 *    decision served across any of them is a correctness bug (country-switch
 *    staleness being a tracked bug class here, TWO-24867). Every bust case below
 *    asserts the provider IS called again.
 *
 *  - The cache is UX-only. checkTwoOrderIntentApprovalAtPayment() is the
 *    authoritative gate and always calls the provider, even with a fresh
 *    approved decision cached for the identical snapshot.
 */
final class CheckoutLatencySpec
{
    public static function runAll(): void
    {
        // Nested company lookup
        self::testRepeatedUnverifiableOrgNumberIsLookedUpOnlyOnce();
        self::testVerifiedOrgNumberIsLookedUpOnlyOnce();
        self::testCountryChangeBustsTheVerificationMiss();
        self::testOrgNumberChangeBustsTheVerificationMiss();
        self::testAddressSwitchBustsTheVerificationMiss();
        self::testVerificationMissExpiresAfterTtl();
        self::testSuccessfulVerificationForgetsAnEarlierMiss();

        // Order-intent snapshot dedupe
        self::testUnchangedSnapshotIsServedFromCache();
        self::testCachedDecisionPreservesDeclineAsWellAsApproval();
        self::testCartChangeBustsTheCachedDecision();
        self::testCountrySwitchBustsTheCachedDecision();
        self::testCompanyChangeBustsTheCachedDecisionOnTheSameCart();
        self::testAddressSwitchBustsTheCachedDecision();
        self::testCachedDecisionExpiresAfterTtl();
        self::testDecisionIsNeverStoredWithoutAPendingSnapshot();
        self::testClearingDropsTheCachedDecision();
        self::testIntentHashIsNotTheOrderCreateIdempotencySnapshotHash();

        // The authoritative gate is never served from the cache
        self::testPaymentSubmitAlwaysCallsProviderDespiteCachedApproval();
    }

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        PrestaShopLogger::reset();
        Context::getContext()->cookie = new Cookie();
    }

    // -----------------------------------------------------------------
    // Nested company lookup
    // -----------------------------------------------------------------

    /**
     * Module that counts /companies/v2/company round trips instead of making
     * them. $resolvable maps "ORGNUMBER|COUNTRY" to a verified company.
     */
    private static function countingModule(array $resolvable = []): TwopaymentTestHarness
    {
        return new class($resolvable) extends TwopaymentTestHarness {
            public int $companyLookups = 0;
            /** @var array<string,array{name:string,organization_number:string}> */
            private array $resolvable;

            public function __construct(array $resolvable)
            {
                parent::__construct();
                $this->resolvable = $resolvable;
            }

            public function verifyCompanyByOrgNumber($orgNumber, $countryIso)
            {
                ++$this->companyLookups;
                $key = strtoupper(trim((string) $orgNumber)) . '|' . strtoupper(trim((string) $countryIso));

                return $this->resolvable[$key] ?? null;
            }
        };
    }

    /**
     * The measured regression: six checkout updates against an address whose org
     * number Two cannot resolve used to cost six blocking lookups. One now.
     */
    private static function testRepeatedUnverifiableOrgNumberIsLookedUpOnlyOnce(): void
    {
        self::reset();
        $module = self::countingModule();

        for ($i = 0; $i < 6; ++$i) {
            $result = $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1901);
            TinyAssert::same(null, $result, 'An unresolvable org number must keep resolving to null');
        }

        TinyAssert::same(1, $module->companyLookups, 'Six identical checkout updates must cost one company lookup');
    }

    private static function testVerifiedOrgNumberIsLookedUpOnlyOnce(): void
    {
        self::reset();
        $module = self::countingModule([
            'B12345678|ES' => ['name' => 'ACME S.L.', 'organization_number' => 'B12345678'],
        ]);

        // The success path is memoised by the caller's two_company_* cookie, so
        // assert the resolved value is stable rather than re-deriving it here.
        for ($i = 0; $i < 4; ++$i) {
            $result = $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1901);
            TinyAssert::same('ACME S.L.', $result['name']);
        }

        TinyAssert::same(4, $module->companyLookups, 'A verified org number is cached by the caller, not by the miss marker');
    }

    private static function testCountryChangeBustsTheVerificationMiss(): void
    {
        self::reset();
        $module = self::countingModule();

        $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1901);
        TinyAssert::same(1, $module->companyLookups);

        // Country switch: must NOT inherit the ES miss (TWO-24867 bug class).
        $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'GB', 1901);
        TinyAssert::same(2, $module->companyLookups, 'A country switch must re-verify the org number');
    }

    private static function testOrgNumberChangeBustsTheVerificationMiss(): void
    {
        self::reset();
        $module = self::countingModule();

        $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1901);
        $module->getTwoVerifiedCompanyForOrgNumber('B87654321', 'ES', 1901);

        TinyAssert::same(2, $module->companyLookups, 'A corrected org number must re-verify');
    }

    private static function testAddressSwitchBustsTheVerificationMiss(): void
    {
        self::reset();
        $module = self::countingModule();

        $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1901);
        $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1902);

        TinyAssert::same(2, $module->companyLookups, 'Switching billing address must re-verify');
    }

    /**
     * The miss is a latency guard, not a verdict: a provider hiccup must
     * self-heal inside the same checkout session.
     */
    private static function testVerificationMissExpiresAfterTtl(): void
    {
        self::reset();
        $module = self::countingModule();

        $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1901);
        TinyAssert::same(1, $module->companyLookups);

        // Age the marker past its TTL.
        $encoded = (string) Context::getContext()->cookie->two_company_verify_miss;
        $decoded = json_decode($encoded, true);
        $decoded['at'] = time() - (Twopayment::COMPANY_VERIFY_MISS_CACHE_TTL + 1);
        Context::getContext()->cookie->two_company_verify_miss = json_encode($decoded);

        $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1901);
        TinyAssert::same(2, $module->companyLookups, 'An expired miss must re-verify');
    }

    private static function testSuccessfulVerificationForgetsAnEarlierMiss(): void
    {
        self::reset();
        $module = self::countingModule();

        $module->getTwoVerifiedCompanyForOrgNumber('B12345678', 'ES', 1901);
        TinyAssert::true(!Tools::isEmpty((string) Context::getContext()->cookie->two_company_verify_miss));

        // A different, resolvable org number on the same address.
        $resolving = self::countingModule([
            'B87654321|ES' => ['name' => 'ACME S.L.', 'organization_number' => 'B87654321'],
        ]);
        $resolving->getTwoVerifiedCompanyForOrgNumber('B87654321', 'ES', 1901);

        TinyAssert::true(
            Tools::isEmpty((string) (Context::getContext()->cookie->two_company_verify_miss ?? '')),
            'A successful verification must clear the stale miss marker'
        );
    }

    // -----------------------------------------------------------------
    // Order-intent snapshot dedupe
    // -----------------------------------------------------------------

    /**
     * Minimal intent payload. Only the fields the snapshot hash reads matter;
     * $overrides mutates one decision input at a time.
     */
    private static function intentPayload(array $overrides = []): array
    {
        $payload = [
            'gross_amount' => '121.00',
            'net_amount' => '100.00',
            'tax_amount' => '21.00',
            'discount_amount' => '0.00',
            'currency' => 'EUR',
            'invoice_type' => 'FUNDED_INVOICE',
            'buyer' => [
                'company' => [
                    'company_name' => 'ACME S.L.',
                    'country_prefix' => 'ES',
                    'organization_number' => 'B12345678',
                    'website' => '',
                ],
            ],
            'billing_address' => ['country' => 'ES', 'city' => 'Madrid'],
            'shipping_address' => ['country' => 'ES', 'city' => 'Madrid'],
            'line_items' => [[
                'type' => 'PHYSICAL',
                'quantity' => 1,
                'unit_price' => '100.00',
                'net_amount' => '100.00',
                'tax_amount' => '21.00',
                'gross_amount' => '121.00',
                'discount_amount' => '0.00',
                'tax_rate' => '0.21',
            ]],
        ];

        foreach ($overrides as $path => $value) {
            $keys = explode('.', (string) $path);
            $cursor = &$payload;
            foreach ($keys as $depth => $key) {
                if ($depth === count($keys) - 1) {
                    $cursor[$key] = $value;
                } else {
                    $cursor = &$cursor[$key];
                }
            }
            unset($cursor);
        }

        return $payload;
    }

    private static function cart(int $idAddress = 1901): Cart
    {
        $cart = new Cart(901);
        $cart->id_customer = 901;
        $cart->id_currency = 978;
        $cart->id_address_invoice = $idAddress;
        $cart->id_address_delivery = $idAddress;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        return $cart;
    }

    /**
     * One checkout update as the handler runs it: hash the snapshot, look for a
     * cached decision, mark the snapshot pending, call the provider only on a
     * miss, then record whatever decision came back.
     *
     * @return bool Whether the provider round trip actually happened
     */
    private static function checkoutUpdate(
        Twopayment $module,
        Cart $cart,
        array $payload,
        bool $approved = true
    ): bool {
        $hash = $module->calculateTwoOrderIntentSnapshotHash($cart, $payload);
        $cached = $module->getTwoCachedOrderIntentDecision($hash);
        $module->markTwoPendingOrderIntentSnapshot($hash);

        $calledProvider = false;
        if ($cached === null) {
            $calledProvider = true;
        } else {
            TinyAssert::same($approved, $cached['approved'], 'A cached decision must round-trip its approval verbatim');
        }

        // The browser reports the decision either way.
        $module->storeTwoOrderIntentDecisionForPendingSnapshot($approved);

        return $calledProvider;
    }

    /**
     * The measured regression: four checkout updates with byte-identical
     * decision inputs used to cost four /v1/order_intent round trips. One now.
     */
    private static function testUnchangedSnapshotIsServedFromCache(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::cart();
        $payload = self::intentPayload();

        $calls = 0;
        for ($i = 0; $i < 4; ++$i) {
            $calls += self::checkoutUpdate($module, $cart, $payload) ? 1 : 0;
        }

        TinyAssert::same(1, $calls, 'Four unchanged checkout updates must cost one intent round trip');
    }

    private static function testCachedDecisionPreservesDeclineAsWellAsApproval(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::cart();
        $payload = self::intentPayload();

        TinyAssert::true(self::checkoutUpdate($module, $cart, $payload, false));
        // checkoutUpdate() asserts the cached approval matches on the hit.
        TinyAssert::false(self::checkoutUpdate($module, $cart, $payload, false), 'A cached decline must dedupe too');

        $hash = $module->calculateTwoOrderIntentSnapshotHash($cart, $payload);
        TinyAssert::false($module->getTwoCachedOrderIntentDecision($hash)['approved']);
    }

    private static function testCartChangeBustsTheCachedDecision(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::cart();

        TinyAssert::true(self::checkoutUpdate($module, $cart, self::intentPayload()));

        // Buyer bumps the quantity: gross and the line item both move.
        $changed = self::intentPayload([
            'gross_amount' => '242.00',
            'line_items' => [[
                'type' => 'PHYSICAL',
                'quantity' => 2,
                'unit_price' => '100.00',
                'net_amount' => '200.00',
                'tax_amount' => '42.00',
                'gross_amount' => '242.00',
                'discount_amount' => '0.00',
                'tax_rate' => '0.21',
            ]],
        ]);

        TinyAssert::true(
            self::checkoutUpdate($module, $cart, $changed),
            'A cart change must re-run the intent check, never serve the old decision'
        );
    }

    /**
     * Country-switch staleness is a tracked bug class here (TWO-24867): a cache
     * that survives a country switch is a correctness bug, not a latency win.
     */
    private static function testCountrySwitchBustsTheCachedDecision(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::cart();

        TinyAssert::true(self::checkoutUpdate($module, $cart, self::intentPayload()));

        $switched = self::intentPayload([
            'buyer.company.country_prefix' => 'GB',
            'billing_address' => ['country' => 'GB', 'city' => 'London'],
            'shipping_address' => ['country' => 'GB', 'city' => 'London'],
        ]);

        TinyAssert::true(
            self::checkoutUpdate($module, $cart, $switched),
            'A country switch must re-run the intent check'
        );
    }

    /**
     * The buyer re-runs company search and picks a different company against the
     * same saved address and unchanged cart. The order-create snapshot hash
     * cannot see this; the intent hash must.
     */
    private static function testCompanyChangeBustsTheCachedDecisionOnTheSameCart(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::cart();

        TinyAssert::true(self::checkoutUpdate($module, $cart, self::intentPayload()));

        $reselected = self::intentPayload([
            'buyer.company.company_name' => 'OTHER TRADING S.L.',
            'buyer.company.organization_number' => 'B87654321',
        ]);

        TinyAssert::true(
            self::checkoutUpdate($module, $cart, $reselected),
            'Choosing a different company must re-run the intent check'
        );
    }

    private static function testAddressSwitchBustsTheCachedDecision(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $payload = self::intentPayload();

        TinyAssert::true(self::checkoutUpdate($module, self::cart(1901), $payload));
        TinyAssert::true(
            self::checkoutUpdate($module, self::cart(1902), $payload),
            'Switching to another saved address must re-run the intent check'
        );
    }

    private static function testCachedDecisionExpiresAfterTtl(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::cart();
        $payload = self::intentPayload();

        TinyAssert::true(self::checkoutUpdate($module, $cart, $payload));

        $decoded = json_decode((string) Context::getContext()->cookie->two_order_intent_decision, true);
        $decoded['at'] = time() - (Twopayment::ORDER_INTENT_DECISION_CACHE_TTL + 1);
        Context::getContext()->cookie->two_order_intent_decision = json_encode($decoded);

        $hash = $module->calculateTwoOrderIntentSnapshotHash($cart, $payload);
        TinyAssert::same(null, $module->getTwoCachedOrderIntentDecision($hash), 'An expired decision must not be served');
    }

    /**
     * The hash is never taken from the request: with nothing pending, a reported
     * decision is dropped rather than bound to an attacker-chosen snapshot.
     */
    private static function testDecisionIsNeverStoredWithoutAPendingSnapshot(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::same('', $module->storeTwoOrderIntentDecisionForPendingSnapshot(true));

        $hash = $module->calculateTwoOrderIntentSnapshotHash(self::cart(), self::intentPayload());
        TinyAssert::same(null, $module->getTwoCachedOrderIntentDecision($hash));
    }

    private static function testClearingDropsTheCachedDecision(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::cart();
        $payload = self::intentPayload();

        self::checkoutUpdate($module, $cart, $payload);
        $hash = $module->calculateTwoOrderIntentSnapshotHash($cart, $payload);
        TinyAssert::notSame(null, $module->getTwoCachedOrderIntentDecision($hash));

        $module->clearTwoCachedOrderIntentDecision();

        TinyAssert::same(null, $module->getTwoCachedOrderIntentDecision($hash), 'Switching away from Two must drop the decision');
    }

    /**
     * The intent hash composes the order-create snapshot hash but must not BE
     * it - reusing it directly would let a company change slip through, and
     * changing the create hash would rewrite order-create idempotency keys.
     */
    private static function testIntentHashIsNotTheOrderCreateIdempotencySnapshotHash(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::cart();
        $payload = self::intentPayload();

        $intentHash = $module->calculateTwoOrderIntentSnapshotHash($cart, $payload);
        $createHash = $module->calculateTwoCheckoutSnapshotHash($cart, $payload);
        TinyAssert::notSame($createHash, $intentHash);

        // A company-only change moves the intent hash and leaves the create
        // snapshot hash alone, which is exactly why the two cannot be shared.
        $reselected = self::intentPayload([
            'buyer.company.company_name' => 'OTHER TRADING S.L.',
            'buyer.company.organization_number' => 'B87654321',
        ]);
        TinyAssert::notSame($intentHash, $module->calculateTwoOrderIntentSnapshotHash($cart, $reselected));
        TinyAssert::same($createHash, $module->calculateTwoCheckoutSnapshotHash($cart, $reselected));
    }

    // -----------------------------------------------------------------
    // The authoritative gate is never served from the cache
    // -----------------------------------------------------------------

    /**
     * The dedupe is UX-only. Payment submit must still call the provider even
     * with a fresh approved decision cached for the identical snapshot.
     */
    private static function testPaymentSubmitAlwaysCallsProviderDespiteCachedApproval(): void
    {
        self::reset();

        $module = new class extends TwopaymentTestHarness {
            public int $providerCalls = 0;

            public function getTwoIntentOrderData($cart, $customer, $currency, $address)
            {
                return CheckoutLatencySpec::publicIntentPayload();
            }

            protected function shouldRunStrictOrderIntentParityAtPayment()
            {
                return false;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if ($endpoint === '/v1/order_intent') {
                    ++$this->providerCalls;
                }

                return ['http_status' => 200, 'approved' => true];
            }
        };

        $cart = self::cart();
        $payload = self::intentPayload();

        // Prime the UX cache with a fresh approval for this exact snapshot.
        self::checkoutUpdate($module, $cart, $payload, true);
        $hash = $module->calculateTwoOrderIntentSnapshotHash($cart, $payload);
        TinyAssert::notSame(null, $module->getTwoCachedOrderIntentDecision($hash));

        $result = $module->checkTwoOrderIntentApprovalAtPayment($cart, new Customer(), new Currency(), new Address());

        TinyAssert::true($result['approved']);
        TinyAssert::same(1, $module->providerCalls, 'The authoritative payment-submit check must never be served from the UX cache');
    }

    /** Exposed for the anonymous harness above. */
    public static function publicIntentPayload(): array
    {
        return self::intentPayload();
    }
}
