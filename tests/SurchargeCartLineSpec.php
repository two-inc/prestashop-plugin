<?php

declare(strict_types=1);

/**
 * Buyer surcharge as a REAL PrestaShop cart line (hidden virtual product).
 *
 * Covers the three hardest correctness bars of the feature:
 * - idempotent add/remove wiring (select Two twice -> one line; switch away
 *   -> removed; switch back -> one line; fresh request replays -> no dupes,
 *   no stale leftovers),
 * - net-amount parity: the cart line's net is produced by the SAME
 *   computation path as the Two payload's fee line
 *   (buildTwoSurchargeLineItemForCart over the shared quote cache), asserted
 *   end-to-end through getTwoNewOrderData,
 * - tax: PrestaShop applies the merchant-selected TaxRulesGroup
 *   (CONFIG_SURCHARGE_TAX_RULES_GROUP) to the line natively - the hidden fee
 *   product carries the group on its id_tax_rules_group field like any real
 *   product, and the Two payload resolves the SAME group for the SAME
 *   destination (getTwoSurchargeTaxRateForCart), so the sides cannot drift.
 *
 * Plus the money-protective guards: the order-create parity gate (fail
 * closed on cart-vs-payload fee divergence) and the front-controller
 * stale-line guard (other payment module / lost session marker).
 */
final class SurchargeCartLineSpec
{
    private const CART_ID = 8101;
    private const PRODUCT_NET = 100.00;
    private const PRODUCT_GROSS = 105.50;
    /** Merchant's own (pre-existing) tax rules group used by the fixtures. */
    private const TAX_GROUP_ID = 400;
    /** Fixture buyer country (FR) covered by the group at 25%. */
    private const COUNTRY_FR = 33;

    public static function runAll(): void
    {
        self::testSelectAddsExactlyOneLineWithConfiguredTaxRate();
        self::testRepeatedSelectionIsIdempotent();
        self::testDeselectRemovesLineAndReselectReaddsOnce();
        self::testFreshRequestReplayLeavesNoDuplicateOrStaleLine();
        self::testTermChangeUpdatesAmountWithoutDuplicating();
        self::testQuoteFailureRemovesLineAndStaysConsistent();
        self::testCartLineNetMatchesTwoPayloadFeeLine();
        self::testOrderCreateParityGateFailsClosedOnDivergence();
        self::testStaleGuardRemovesLineForOtherPaymentModuleController();
        self::testStaleGuardRemovesLineWhenSessionMarkerLost();
        self::testStaleGuardKeepsLegitimateLine();
        self::testHiddenProductShapeAndLazyCreation();
        self::testStaleSequencedSyncRequestIsIgnoredServerSide();
        self::testAuthoritativeSyncBypassesSequenceGuard();
        self::testSequencedSyncFailsSoftWhenLockHeldByConcurrentRequest();
        self::testConcurrentFirstUseCreatesNoDuplicateHiddenProduct();
        self::testSyncAppliesSelectedTaxRulesGroupToFeeProductAndNeverCreatesTaxObjects();
        self::testFeeTaxFollowsDestinationRulesOfSelectedGroup();
        self::testNoTaxSentinelZeroesFeeTaxForEveryDestination();
        self::testAdminManualAddOfFeeProductToOrderIsBlocked();
        self::testDuplicateFeeOrderDetailRowIsRejectedInAnyContext();
        self::testFirstFeeOrderDetailRowFromOrderCreationIsAllowed();
        self::testFeeGuardTriggerDdlInstalledIdempotentlyAndDropped();
        self::testFeeGuardTriggerEnsuredLazilyOnCartSync();
    }

    /* ---- fixtures ---- */

    private static function makeModule(array $feePerDays = [30 => '5.00']): Twopayment
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '5');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_60', '8');
        // Merchant's own tax rules group, selected in the module config:
        // covers the fixture buyer's country (FR) at 25%. Destinations the
        // group has no rule for resolve 0 (core behaviour).
        StubStore::$taxRulesGroups[self::TAX_GROUP_ID] = ['name' => 'FR standard rate', 'active' => 1];
        StubStore::$taxRuleRates[self::TAX_GROUP_ID] = [self::COUNTRY_FR => 25.0];
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, (string) self::TAX_GROUP_ID);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, '[30,60]');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', '1');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_60', '1');

        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[8201] = [
            'id_country' => 33,
            'company' => 'Acme FR SAS',
            'companyid' => 'FR123456789',
            'address1' => '10 Rue de Paris',
            'city' => 'Paris',
            'postcode' => '75001',
            'phone' => '+33100000000',
            'loaded' => true,
        ];
        StubStore::$addresses[8202] = StubStore::$addresses[8201];
        StubStore::$countries[33] = 'FR';
        StubStore::$customers[8001] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Eva',
            'lastname' => 'Martin',
            'secure_key' => 'secure-key-8001',
            'loaded' => true,
        ];

        StubStore::$cartProducts[self::CART_ID] = [[
            'id_product' => 9401,
            'link_rewrite' => 'plain-item',
            'name' => 'Plain item',
            'description_short' => '',
            'manufacturer_name' => '',
            'ean13' => '',
            'upc' => '',
            'cart_quantity' => 1,
            'price' => self::PRODUCT_NET,
            'total' => self::PRODUCT_NET,
            'total_wt' => self::PRODUCT_GROSS,
            'rate' => 5.5,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[9401] = [['name' => 'Books']];
        StubStore::$images[9401] = ['id_image' => 9401];
        StubStore::$cartTotals[self::CART_ID] = [
            true => [Cart::ONLY_DISCOUNTS => 0.0, Cart::BOTH => self::PRODUCT_GROSS],
            false => [Cart::ONLY_DISCOUNTS => 0.0, Cart::BOTH => self::PRODUCT_NET],
            'average_products_tax_rate' => 5.5,
        ];

        $module = new class($feePerDays) extends TwopaymentTestHarness {
            private array $feePerDays;
            public array $feeRequests = [];
            public $forcedFeeResponse = null;

            public function __construct(array $feePerDays)
            {
                parent::__construct();
                $this->feePerDays = $feePerDays;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->feeRequests[] = $payload;
                if ($this->forcedFeeResponse !== null) {
                    return $this->forcedFeeResponse;
                }
                $days = isset($payload['order_terms']['duration_days']) ? (int) $payload['order_terms']['duration_days'] : 30;
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => $this->feePerDays[$days] ?? '5.00',
                    'currency' => 'EUR',
                ];
            }
        };

        return $module;
    }

    private static function makeCart(): Cart
    {
        $cart = new Cart(self::CART_ID);
        $cart->id_customer = 8001;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 8201;
        $cart->id_address_delivery = 8202;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;
        Context::getContext()->cart = $cart;
        return $cart;
    }

    private static function feeLines(): array
    {
        $productId = (int) Configuration::get('PS_TWO_SURCHARGE_PRODUCT_ID');
        return array_values(array_filter(
            StubStore::$cartProducts[self::CART_ID] ?? [],
            static fn (array $row): bool => (int) $row['id_product'] === $productId
        ));
    }

    /* ---- requirement 2 + 6: add wiring, tax rate ---- */

    private static function testSelectAddsExactlyOneLineWithConfiguredTaxRate(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        $result = $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($result['success']);
        TinyAssert::true($result['changed']);
        TinyAssert::true($result['present']);

        $lines = self::feeLines();
        TinyAssert::count(1, $lines);
        TinyAssert::same(1, (int) $lines[0]['cart_quantity']);
        // Net = quoted buyer_fee_share (5.00); gross applies the merchant's
        // selected tax rules group rate for the buyer's country (FR -> 25%).
        TinyAssert::same(5.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(6.25, round((float) $lines[0]['total_wt'], 2));
        TinyAssert::same(25.0, round((float) $lines[0]['rate'], 2));

        // Cart totals carry the fee line.
        TinyAssert::same(111.75, round((float) $cart->getOrderTotal(true, Cart::BOTH), 2));
        TinyAssert::same(105.00, round((float) $cart->getOrderTotal(false, Cart::BOTH), 2));
    }

    /* ---- requirement 3: idempotency ---- */

    private static function testRepeatedSelectionIsIdempotent(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        $module->syncTwoSurchargeCartLine($cart, true);
        $second = $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($second['success']);
        TinyAssert::false($second['changed'], 'repeat selection must be a no-op');
        TinyAssert::true($second['present']);
        TinyAssert::count(1, self::feeLines());
        TinyAssert::same(111.75, round((float) $cart->getOrderTotal(true, Cart::BOTH), 2));
    }

    private static function testDeselectRemovesLineAndReselectReaddsOnce(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        $module->syncTwoSurchargeCartLine($cart, true);
        $removed = $module->syncTwoSurchargeCartLine($cart, false);
        TinyAssert::true($removed['success']);
        TinyAssert::true($removed['changed']);
        TinyAssert::false($removed['present']);
        TinyAssert::count(0, self::feeLines());
        TinyAssert::same(self::PRODUCT_GROSS, round((float) $cart->getOrderTotal(true, Cart::BOTH), 2));

        // Deselect again: nothing left to remove, still a clean no-op.
        $removedAgain = $module->syncTwoSurchargeCartLine($cart, false);
        TinyAssert::true($removedAgain['success']);
        TinyAssert::false($removedAgain['changed']);

        $readded = $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($readded['changed']);
        TinyAssert::count(1, self::feeLines());
        TinyAssert::same(111.75, round((float) $cart->getOrderTotal(true, Cart::BOTH), 2));
    }

    private static function testFreshRequestReplayLeavesNoDuplicateOrStaleLine(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        $module->syncTwoSurchargeCartLine($cart, true);

        // Simulate the browser reloading mid-selection: a NEW module instance
        // (fresh request state, fresh fee cache) replays the same selection
        // against the persisted cart.
        $reloadedModule = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return ['http_status' => 200, 'buyer_fee_share' => '5.00', 'currency' => 'EUR'];
            }
        };
        $replay = $reloadedModule->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($replay['success']);
        TinyAssert::false($replay['changed'], 'reload replay must not stack a second line');
        TinyAssert::count(1, self::feeLines());
    }

    private static function testTermChangeUpdatesAmountWithoutDuplicating(): void
    {
        $module = self::makeModule([30 => '5.00', 60 => '8.00']);
        $cart = self::makeCart();

        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::same(5.00, round((float) self::feeLines()[0]['total'], 2));

        // Buyer flips the term chip to 60 days -> higher quoted fee.
        Context::getContext()->cookie->two_payment_term = 60;
        $updated = $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($updated['changed'], 'stale amount must be corrected');
        $lines = self::feeLines();
        TinyAssert::count(1, $lines);
        TinyAssert::same(8.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(10.00, round((float) $lines[0]['total_wt'], 2)); // 25% on 8.00
    }

    private static function testQuoteFailureRemovesLineAndStaysConsistent(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::count(1, self::feeLines());

        // Fee quote becomes unavailable: the Two payload would carry no fee
        // line, so the cart line must go too (consistency over stickiness).
        $module->forcedFeeResponse = ['http_status' => 500];
        // New quote signature (term change) bypasses the request/session cache.
        Context::getContext()->cookie->two_payment_term = 60;
        $result = $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($result['success']);
        TinyAssert::true($result['changed']);
        TinyAssert::false($result['present']);
        TinyAssert::count(0, self::feeLines());
    }

    /* ---- requirement 2 + 5: single computation path, parity gate ---- */

    private static function testCartLineNetMatchesTwoPayloadFeeLine(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        // Frontend selection first (the normal flow) ...
        $module->syncTwoSurchargeCartLine($cart, true);
        $cartLine = self::feeLines()[0];

        // ... then the order-create payload built from the same cart.
        $payload = $module->getTwoNewOrderData('merchant-attempt-8101', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $feeLines = array_values(array_filter($payload['line_items'], static function ($item) {
            return isset($item['type']) && $item['type'] === 'SERVICE';
        }));
        TinyAssert::count(1, $feeLines);
        TinyAssert::same(round((float) $cartLine['total'], 2), round((float) $feeLines[0]['net_amount'], 2));
        TinyAssert::same(round((float) $cartLine['total_wt'], 2), round((float) $feeLines[0]['gross_amount'], 2));
        TinyAssert::same('0.25', $feeLines[0]['tax_rate']);
        // Payload totals equal the cart totals INCLUDING the fee line: the
        // buyer's PrestaShop total and the Two invoice are the same money.
        TinyAssert::same('111.75', $payload['gross_amount']);
        TinyAssert::same(
            round((float) $cart->getOrderTotal(true, Cart::BOTH), 2),
            round((float) $payload['gross_amount'], 2)
        );
    }

    private static function testOrderCreateParityGateFailsClosedOnDivergence(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        $module->syncTwoSurchargeCartLine($cart, true);

        // Corrupt the cart line to a stale amount AND disable the self-heal,
        // simulating a sync that cannot reconcile (e.g. broken tax setup).
        foreach (StubStore::$cartProducts[self::CART_ID] as $i => $row) {
            if ((int) $row['id_product'] === (int) Configuration::get('PS_TWO_SURCHARGE_PRODUCT_ID')) {
                $netDelta = 2.00 - (float) $row['total'];
                $grossDelta = 2.50 - (float) $row['total_wt'];
                StubStore::$cartProducts[self::CART_ID][$i]['total'] = 2.00;
                StubStore::$cartProducts[self::CART_ID][$i]['total_wt'] = 2.50;
                // Keep cart totals coherent with the corrupted line so the
                // generic totals reconciliation passes and the FEE parity
                // gate is what trips.
                StubStore::$cartTotals[self::CART_ID][false][Cart::BOTH] = round(
                    (float) StubStore::$cartTotals[self::CART_ID][false][Cart::BOTH] + $netDelta,
                    2
                );
                StubStore::$cartTotals[self::CART_ID][true][Cart::BOTH] = round(
                    (float) StubStore::$cartTotals[self::CART_ID][true][Cart::BOTH] + $grossDelta,
                    2
                );
            }
        }
        $gatedModule = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return ['http_status' => 200, 'buyer_fee_share' => '5.00', 'currency' => 'EUR'];
            }

            public function syncTwoSurchargeCartLine($cart, $selected, $syncSeq = null)
            {
                return ['success' => false, 'changed' => false, 'present' => true];
            }
        };

        TinyAssert::throws(static function () use ($gatedModule, $cart) {
            $gatedModule->getTwoNewOrderData('merchant-attempt-8102', $cart, [
                'merchant_confirmation_url' => 'https://shop.local/confirm',
                'merchant_cancel_order_url' => 'https://shop.local/cancel',
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => '',
            ]);
        }, 'Surcharge line mismatch');
    }

    /* ---- requirement 3: stale-line guards ---- */

    private static function testStaleGuardRemovesLineForOtherPaymentModuleController(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::count(1, self::feeLines());

        // Another payment module's validation controller executes: the fee
        // must be stripped before that module computes its totals - even
        // though the session marker is still valid.
        $otherController = new \stdClass();
        $otherController->module = (object) ['name' => 'ps_wirepayment'];
        $module->hookActionFrontControllerInitAfter(['controller' => $otherController]);
        TinyAssert::count(0, self::feeLines());
        TinyAssert::same(self::PRODUCT_GROSS, round((float) $cart->getOrderTotal(true, Cart::BOTH), 2));
    }

    private static function testStaleGuardRemovesLineWhenSessionMarkerLost(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        $module->syncTwoSurchargeCartLine($cart, true);

        // Abandoned cart resumed in a fresh session: cookie marker is gone.
        Context::getContext()->cookie = new Cookie();
        $coreController = new \stdClass(); // core controller, no ->module
        $module->hookActionFrontControllerInitAfter(['controller' => $coreController]);
        TinyAssert::count(0, self::feeLines());
    }

    private static function testStaleGuardKeepsLegitimateLine(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        $module->syncTwoSurchargeCartLine($cart, true);

        // Valid marker + core controller (checkout page render): keep.
        $coreController = new \stdClass();
        $module->hookActionFrontControllerInitAfter(['controller' => $coreController]);
        TinyAssert::count(1, self::feeLines());

        // Own module controller (payment/confirmation/sync endpoint): keep
        // even without inspecting the marker.
        $ownController = new \stdClass();
        $ownController->module = (object) ['name' => 'twopayment'];
        $module->hookActionFrontControllerInitAfter(['controller' => $ownController]);
        TinyAssert::count(1, self::feeLines());
    }

    /* ---- server-side request-ordering guard (rapid method switches) ---- */

    private static function testStaleSequencedSyncRequestIsIgnoredServerSide(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        // Buyer clicks: away from Two (seq 100, still in flight), then back
        // to Two (seq 200). The NEWER request completes first ...
        $newer = $module->syncTwoSurchargeCartLine($cart, true, 200);
        TinyAssert::true($newer['success']);
        TinyAssert::true($newer['present']);
        TinyAssert::count(1, self::feeLines());

        // ... then the OLDER "remove" request lands: it must be a no-op that
        // reports the CURRENT state, not strip the line the newer click added.
        $older = $module->syncTwoSurchargeCartLine($cart, false, 100);
        TinyAssert::true($older['success']);
        TinyAssert::false($older['changed'], 'stale request must not mutate the cart');
        TinyAssert::true($older['present'], 'stale request reports the current state');
        TinyAssert::count(1, self::feeLines());
        TinyAssert::same(111.75, round((float) $cart->getOrderTotal(true, Cart::BOTH), 2));

        // Equal seq (transport retry of an already-applied request) is stale too.
        $replay = $module->syncTwoSurchargeCartLine($cart, true, 200);
        TinyAssert::true($replay['success']);
        TinyAssert::false($replay['changed']);

        // A genuinely newer request still applies.
        $newest = $module->syncTwoSurchargeCartLine($cart, false, 300);
        TinyAssert::true($newest['success']);
        TinyAssert::true($newest['changed']);
        TinyAssert::count(0, self::feeLines());
    }

    private static function testAuthoritativeSyncBypassesSequenceGuard(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        // Buyer AJAX stored seq 500 and removed the line.
        $module->syncTwoSurchargeCartLine($cart, true, 400);
        $module->syncTwoSurchargeCartLine($cart, false, 500);
        TinyAssert::count(0, self::feeLines());

        // The order-create self-heal (buildTwoOrderPricingData) passes NO
        // seq: it is the final authoritative sync before charging and must
        // always apply, whatever the stored sequence says.
        $selfHeal = $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($selfHeal['success']);
        TinyAssert::true($selfHeal['changed']);
        TinyAssert::count(1, self::feeLines());
    }

    private static function testSequencedSyncFailsSoftWhenLockHeldByConcurrentRequest(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        // Another request holds this cart's sync lock: the sequenced call
        // reports success=false (frontend retries / server gate stays
        // authoritative) and mutates nothing.
        StubStore::$dbLocks['two_surcharge_sync_' . self::CART_ID] = true;
        $result = $module->syncTwoSurchargeCartLine($cart, true, 100);
        TinyAssert::false($result['success']);
        TinyAssert::false($result['changed']);
        TinyAssert::count(0, self::feeLines());
        unset(StubStore::$dbLocks['two_surcharge_sync_' . self::CART_ID]);
    }

    /* ---- concurrent first-use creation guards (advisory locks) ---- */

    private static function testConcurrentFirstUseCreatesNoDuplicateHiddenProduct(): void
    {
        $module = self::makeModule();
        self::makeCart();

        // Scenario 1: another request holds the creation lock RIGHT NOW.
        // This request must not create a second product; it backs off (0)
        // and the next sync retries.
        StubStore::$dbLocks['two_surcharge_product_create'] = true;
        $productsBefore = count(StubStore::$products);
        TinyAssert::same(0, $module->getTwoSurchargeCartProductId(true));
        TinyAssert::same($productsBefore, count(StubStore::$products), 'lock loser must not create a product');
        unset(StubStore::$dbLocks['two_surcharge_product_create']);

        // Scenario 2: the concurrent winner finished and wrote the id to the
        // configuration TABLE (this request's Configuration cache is stale -
        // the stub's shared store stands in for the DB read under the lock).
        // The double-check under the lock must adopt the winner's product
        // instead of creating a duplicate.
        $winnerId = $module->getTwoSurchargeCartProductId(true);
        TinyAssert::true($winnerId > 0);
        $productsAfterWinner = count(StubStore::$products);
        TinyAssert::same($winnerId, $module->getTwoSurchargeCartProductId(true));
        TinyAssert::same($productsAfterWinner, count(StubStore::$products), 'no duplicate hidden product after the race');
    }

    /**
     * TWO-25071: the module assigns the MERCHANT's selected TaxRulesGroup to
     * the hidden product's id_tax_rules_group (the same field every real
     * product uses) and never creates Tax/TaxRulesGroup/TaxRule objects of
     * its own (the synthetic-graph machinery is gone).
     */
    private static function testSyncAppliesSelectedTaxRulesGroupToFeeProductAndNeverCreatesTaxObjects(): void
    {
        $module = self::makeModule([30 => '5.00', 60 => '8.00']);
        $cart = self::makeCart();

        $module->syncTwoSurchargeCartLine($cart, true);
        $productId = (int) Configuration::get('PS_TWO_SURCHARGE_PRODUCT_ID');
        TinyAssert::same(self::TAX_GROUP_ID, (int) (new Product($productId))->id_tax_rules_group, 'fee product carries the merchant-selected group');
        TinyAssert::count(0, StubStore::$taxes, 'module must not create a Tax');
        TinyAssert::count(1, StubStore::$taxRulesGroups, 'only the merchant-owned fixture group exists');
        TinyAssert::count(0, StubStore::$taxRules, 'module must not create a TaxRule');

        // Merchant re-points the selection in the module config: the next
        // sync self-heals the product assignment (no new objects, ever).
        StubStore::$taxRulesGroups[401] = ['name' => 'Reduced rate', 'active' => 1];
        StubStore::$taxRuleRates[401] = [self::COUNTRY_FR => 10.0];
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '401');
        Context::getContext()->cookie->two_payment_term = 60; // amount change forces a re-ensure
        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::same(401, (int) (new Product($productId))->id_tax_rules_group, 'selection change re-points the product on the next sync');
        TinyAssert::count(0, StubStore::$taxes);
        TinyAssert::count(0, StubStore::$taxRules);
        // And the re-priced line applies the NEW group's FR rate (10%) to
        // the 60-day quote (8.00 net -> 8.80 gross).
        $lines = self::feeLines();
        TinyAssert::count(1, $lines);
        TinyAssert::same(8.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(8.80, round((float) $lines[0]['total_wt'], 2));
    }

    /**
     * TWO-25071: destination-based rates. The selected group taxes the fee
     * for a covered destination and zero-rates it for a destination the
     * group has NO rule for - and the Two payload line resolves the SAME
     * rate as the PS cart line in both cases (parity by construction),
     * including a genuine multi-rate stacking destination (6% + 2% -> 8%).
     */
    private static function testFeeTaxFollowsDestinationRulesOfSelectedGroup(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        // Group covers FR at 25%, CA-style stacked 6%+2% for country 40,
        // and has NO rule for country 47 (NO).
        StubStore::$taxRuleRates[self::TAX_GROUP_ID] = [
            self::COUNTRY_FR => 25.0,
            40 => [6.0, 2.0],
        ];
        StubStore::$countries[40] = 'CA';
        StubStore::$addresses[8203] = ['id_country' => 40, 'loaded' => true] + StubStore::$addresses[8201];
        StubStore::$addresses[8204] = ['id_country' => 47, 'loaded' => true] + StubStore::$addresses[8201];

        // Covered destination (FR, 25%): 5.00 net -> 6.25 gross.
        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::same(6.25, round((float) self::feeLines()[0]['total_wt'], 2));
        $payloadLine = $module->buildTwoSurchargeLineItemForCart($cart, self::PRODUCT_GROSS);
        TinyAssert::same('0.25', $payloadLine['tax_rate']);
        TinyAssert::same('6.25', $payloadLine['gross_amount']);

        // Stacking destination (6% + 2% combined = 8%): both sides at 8%.
        $cart->id_address_invoice = 8203;
        $cart->id_address_delivery = 8203;
        $module->syncTwoSurchargeCartLine($cart, true);
        $lines = self::feeLines();
        TinyAssert::same(5.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(5.40, round((float) $lines[0]['total_wt'], 2), 'stacked 6%+2% must apply additively (8%)');
        $payloadLine = $module->buildTwoSurchargeLineItemForCart($cart, self::PRODUCT_GROSS);
        TinyAssert::same('0.08', $payloadLine['tax_rate']);
        TinyAssert::same('0.40', $payloadLine['tax_amount']);
        TinyAssert::same('5.40', $payloadLine['gross_amount'], 'payload gross matches the PS line gross for the stacked destination');

        // Uncovered destination (NO rule): zero-rated on BOTH sides.
        $cart->id_address_invoice = 8204;
        $cart->id_address_delivery = 8204;
        $module->syncTwoSurchargeCartLine($cart, true);
        $lines = self::feeLines();
        TinyAssert::same(5.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(5.00, round((float) $lines[0]['total_wt'], 2), 'no rule for the destination -> untaxed line');
        $payloadLine = $module->buildTwoSurchargeLineItemForCart($cart, self::PRODUCT_GROSS);
        TinyAssert::same('0', $payloadLine['tax_rate']);
        TinyAssert::same('5.00', $payloadLine['gross_amount']);
    }

    /**
     * TWO-25071: selecting the "No tax" sentinel (id 0) zeroes the fee tax
     * for EVERY destination - no special-case code, it is core's own
     * untaxed-group semantics (live-container verified).
     */
    private static function testNoTaxSentinelZeroesFeeTaxForEveryDestination(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '0');

        foreach ([self::COUNTRY_FR => 8201, 47 => 8205] as $countryId => $addressId) {
            StubStore::$countries[47] = 'NO';
            StubStore::$addresses[8205] = ['id_country' => 47, 'loaded' => true] + StubStore::$addresses[8201];
            $cart->id_address_invoice = $addressId;
            $cart->id_address_delivery = $addressId;
            $module->syncTwoSurchargeCartLine($cart, true);
            $lines = self::feeLines();
            TinyAssert::count(1, $lines);
            TinyAssert::same(5.00, round((float) $lines[0]['total'], 2));
            TinyAssert::same(5.00, round((float) $lines[0]['total_wt'], 2), 'No tax sentinel must produce an untaxed line for country ' . $countryId);
            $payloadLine = $module->buildTwoSurchargeLineItemForCart($cart, self::PRODUCT_GROSS);
            TinyAssert::same('0', $payloadLine['tax_rate']);
            TinyAssert::same('5.00', $payloadLine['gross_amount']);
        }
    }

    /* ---- order-level guard: no manual/duplicate fee rows (BO order edit) ---- */

    /** Minimal stand-in for the OrderDetail the hook receives. */
    private static function makeOrderDetailStub(int $productId, int $idOrder): \stdClass
    {
        $orderDetail = new \stdClass();
        $orderDetail->product_id = $productId;
        $orderDetail->id_order = $idOrder;
        return $orderDetail;
    }

    private static function testAdminManualAddOfFeeProductToOrderIsBlocked(): void
    {
        $module = self::makeModule();
        self::makeCart();
        $feeProductId = $module->getTwoSurchargeCartProductId(true);

        // Back-office order edit: employee finds the hidden product through
        // the AdminOrders "Add product" search. Must throw - even as the
        // FIRST row (this product is module-managed only).
        $adminController = new \stdClass();
        $adminController->controller_type = 'admin';
        Context::getContext()->controller = $adminController;

        TinyAssert::throws(static function () use ($module, $feeProductId) {
            $module->hookActionObjectOrderDetailAddBefore([
                'object' => self::makeOrderDetailStub($feeProductId, 7001),
            ]);
        }, 'cannot be added to an order manually');

        // Ordinary catalog products are untouched by the guard.
        $module->hookActionObjectOrderDetailAddBefore([
            'object' => self::makeOrderDetailStub(9401, 7001),
        ]);
    }

    private static function testDuplicateFeeOrderDetailRowIsRejectedInAnyContext(): void
    {
        $module = self::makeModule();
        self::makeCart();
        $feeProductId = $module->getTwoSurchargeCartProductId(true);

        // Front context (order-creation pipeline), but the order ALREADY
        // carries the fee row: a second one is always a duplicate charge.
        $frontController = new \stdClass();
        $frontController->controller_type = 'front';
        Context::getContext()->controller = $frontController;
        StubStore::$orderDetails[] = ['id_order' => 7002, 'product_id' => $feeProductId];

        TinyAssert::throws(static function () use ($module, $feeProductId) {
            $module->hookActionObjectOrderDetailAddBefore([
                'object' => self::makeOrderDetailStub($feeProductId, 7002),
            ]);
        }, 'refusing to add a duplicate fee row');

        // A DIFFERENT order without a fee row is unaffected.
        $module->hookActionObjectOrderDetailAddBefore([
            'object' => self::makeOrderDetailStub($feeProductId, 7003),
        ]);
    }

    private static function testFirstFeeOrderDetailRowFromOrderCreationIsAllowed(): void
    {
        $module = self::makeModule();
        self::makeCart();
        $feeProductId = $module->getTwoSurchargeCartProductId(true);

        // Legitimate path: validateOrder creating the order's FIRST fee row
        // in a front context - the hook must not interfere.
        $frontController = new \stdClass();
        $frontController->controller_type = 'front';
        Context::getContext()->controller = $frontController;

        $module->hookActionObjectOrderDetailAddBefore([
            'object' => self::makeOrderDetailStub($feeProductId, 7004),
        ]);

        // And when the hidden product was never created (module never used),
        // the guard is inert for every product.
        StubStore::reset();
        $module->hookActionObjectOrderDetailAddBefore([
            'object' => self::makeOrderDetailStub(12345, 7005),
        ]);
    }

    /* ---- DB-level fee-guard trigger (the ACTUAL enforcement layer) ---- */

    /**
     * TESTING-LAYER NOTE: the stub Db has no SQL engine, so these tests can
     * only assert the DDL the module issues (shape, idempotence, drop). The
     * trigger's REJECTION semantics - duplicate fee row rejected, fee row on
     * a fee-less-cart order rejected, legitimate first row and ordinary
     * products unaffected - CANNOT be exercised by stubs and are verified
     * against a live PS8 + MariaDB container (real CREATE TRIGGER, real
     * INSERTs); see the commit message for the verified scenarios.
     */
    private static function testFeeGuardTriggerDdlInstalledIdempotentlyAndDropped(): void
    {
        $module = self::makeModule();
        $triggerName = _DB_PREFIX_ . 'twopayment_fee_guard';

        TinyAssert::true($module->installTwoOrderDetailFeeGuardTrigger());
        TinyAssert::true(isset(StubStore::$dbTriggers[$triggerName]), 'trigger DDL issued');
        $ddl = StubStore::$dbTriggers[$triggerName];
        TinyAssert::true(strpos($ddl, 'BEFORE INSERT ON `' . _DB_PREFIX_ . 'order_detail`') !== false, 'guards order_detail inserts');
        TinyAssert::same(2, substr_count($ddl, "SIGNAL SQLSTATE '45000'"), 'both rules reject via SIGNAL: duplicate row + fee-less-cart order');
        TinyAssert::true(strpos($ddl, Twopayment::CONFIG_SURCHARGE_PRODUCT_ID) !== false, 'fee product id resolved live from configuration (survives product recreation)');
        TinyAssert::true(strpos($ddl, '`' . _DB_PREFIX_ . 'cart_product`') !== false, 'fee row only accepted for an order whose cart carries the fee line');

        // Idempotent: a second install sees the existing trigger and issues
        // no DDL at all.
        $executedBefore = count(StubStore::$dbExecuted);
        TinyAssert::true($module->installTwoOrderDetailFeeGuardTrigger());
        TinyAssert::same($executedBefore, count(StubStore::$dbExecuted), 'no duplicate CREATE TRIGGER');

        // Uninstall drops it (via deleteTwoTables -> dropTwoOrderDetailFeeGuardTrigger).
        $drop = new ReflectionMethod($module, 'dropTwoOrderDetailFeeGuardTrigger');
        $drop->setAccessible(true);
        $drop->invoke($module);
        TinyAssert::false(isset(StubStore::$dbTriggers[$triggerName]), 'trigger dropped at uninstall');
    }

    private static function testFeeGuardTriggerEnsuredLazilyOnCartSync(): void
    {
        // Upgrade path: install() never re-ran, so the trigger is absent -
        // the first checkout cart sync must (re)install it.
        $module = self::makeModule();
        $cart = self::makeCart();
        $triggerName = _DB_PREFIX_ . 'twopayment_fee_guard';
        TinyAssert::false(isset(StubStore::$dbTriggers[$triggerName]));

        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true(isset(StubStore::$dbTriggers[$triggerName]), 'lazy ensure installs the trigger on first sync');

        // Once per request only: a second sync issues no further trigger DDL.
        $createCount = static function (): int {
            return count(array_filter(StubStore::$dbExecuted, static function ($sql) {
                return strpos($sql, 'CREATE TRIGGER') !== false;
            }));
        };
        $before = $createCount();
        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::same($before, $createCount(), 'ensure is memoised per request');
    }

    /* ---- requirement 1: hidden product shape ---- */

    private static function testHiddenProductShapeAndLazyCreation(): void
    {
        $module = self::makeModule();
        self::makeCart();

        TinyAssert::same(0, $module->getTwoSurchargeCartProductId(false), 'no eager creation');

        $productId = $module->getTwoSurchargeCartProductId(true);
        TinyAssert::true($productId > 0);
        $product = new Product($productId);
        TinyAssert::same('none', (string) $product->visibility);
        TinyAssert::same(0, (int) $product->indexed);
        TinyAssert::same(1, (int) $product->is_virtual);
        TinyAssert::same(1, (int) $product->active);
        TinyAssert::same(1, (int) $product->available_for_order);
        TinyAssert::same(1, (int) $product->out_of_stock);
        TinyAssert::same(Twopayment::TWO_SURCHARGE_PRODUCT_REFERENCE, (string) $product->reference);

        // Lazy-create is itself idempotent: same id on every subsequent call.
        TinyAssert::same($productId, $module->getTwoSurchargeCartProductId(true));
        TinyAssert::same($productId, $module->getTwoSurchargeCartProductId(false));

        // A stale stored id pointing at a DIFFERENT product is rejected and
        // recreated (reference cross-check).
        Configuration::updateValue('PS_TWO_SURCHARGE_PRODUCT_ID', 9401);
        $recreated = $module->getTwoSurchargeCartProductId(true);
        TinyAssert::notSame(9401, $recreated);
        TinyAssert::true($recreated > 0);
    }
}
