<?php

declare(strict_types=1);

/**
 * Buyer surcharge as a REAL PrestaShop cart line (hidden virtual product).
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
        self::testQuoteFailureKeepsLineAndFailsLoudly();
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
        self::testVatExemptB2BCartUntaxedOnBothCartLineAndPayload();
        self::testAdminManualAddOfFeeProductToOrderIsBlocked();
        self::testDuplicateFeeOrderDetailRowIsRejectedInAnyContext();
        self::testFirstFeeOrderDetailRowFromOrderCreationIsAllowed();
        self::testFeeGuardTriggerDdlInstalledIdempotentlyAndDropped();
        self::testFeeGuardTriggerEnsuredLazilyOnCartSync();
        self::testActionPresentCartMovesSurchargeToOwnRowBeforeShipping();
        self::testActionPresentCartNoOpsWhenSurchargeNotSelected();
        self::testActionPresentCartNoOpsWhenRowAbsentFromPresentedProducts();
    }

    /* ---- fixtures ---- */

    private static function makeModule(array $feePerDays = [30 => '5.00']): Twopayment
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '5');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_60', '8');
        // Destinations the group has no rule for resolve 0 (core behaviour).
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
        // Declared-rate relay (TWO-24880): the ordinary product's own
        // tax-rules group (flat 5.5%, matching total/total_wt) — distinct
        // from the fee product's merchant-selected group (TAX_GROUP_ID).
        StubStore::$products[9401]['id_tax_rules_group'] = 500;
        StubStore::$taxRuleRates[500] = 5.5;
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
        // Gross applies the selected group's rate for the buyer's country (FR -> 25%).
        TinyAssert::same(5.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(6.25, round((float) $lines[0]['total_wt'], 2));
        TinyAssert::same(25.0, round((float) $lines[0]['rate'], 2));

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

        // Browser reload mid-selection: a NEW module instance (fresh request
        // state, fresh fee cache) replays the selection on the persisted cart.
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

        Context::getContext()->cookie->two_payment_term = 60;
        $updated = $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($updated['changed'], 'stale amount must be corrected');
        $lines = self::feeLines();
        TinyAssert::count(1, $lines);
        TinyAssert::same(8.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(10.00, round((float) $lines[0]['total_wt'], 2)); // 25% on 8.00
    }

    /**
     * TWO-25269: an unavailable quote is a FAILURE, not a deselection -
     * removing the line instead is a silent undercharge. The cart keeps its
     * line, success is false, and it is logged. Whole-store causes are caught
     * earlier by isTwoSurchargeQuotableForCart withholding the payment option;
     * anything that only fails here reaches the order-create parity gate.
     */
    private static function testQuoteFailureKeepsLineAndFailsLoudly(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::count(1, self::feeLines());

        $module->forcedFeeResponse = ['http_status' => 500];
        // New quote signature (term change) bypasses the request/session cache.
        Context::getContext()->cookie->two_payment_term = 60;
        PrestaShopLogger::reset();
        $result = $module->syncTwoSurchargeCartLine($cart, true);

        TinyAssert::false($result['success'], 'an unavailable quote is a failure, not a silent success');
        TinyAssert::false($result['changed'], 'nothing may be changed on an unquotable sync');
        TinyAssert::true($result['present'], 'the caller must be told the existing line is still there');
        TinyAssert::count(1, self::feeLines(), 'the surcharge line must NOT be removed - removing it is the undercharge');

        $logged = false;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'refusing to remove the surcharge line') !== false && $entry['severity'] === 3) {
                $logged = true;
            }
        }
        TinyAssert::true($logged, 'refusing to remove must be logged at error level');
    }

    /* ---- requirement 2 + 5: single computation path, parity gate ---- */

    private static function testCartLineNetMatchesTwoPayloadFeeLine(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        $module->syncTwoSurchargeCartLine($cart, true);
        $cartLine = self::feeLines()[0];

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
        // Payload totals include the fee line: the buyer's PrestaShop total
        // and the Two invoice are the same money.
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

        // Other module's validation controller: fee stripped before that
        // module computes totals, even though the session marker is valid.
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

        // Own module controller: keep even without inspecting the marker.
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

        // Buyer clicks away (seq 100, still in flight) then back (seq 200);
        // the NEWER request completes first.
        $newer = $module->syncTwoSurchargeCartLine($cart, true, 200);
        TinyAssert::true($newer['success']);
        TinyAssert::true($newer['present']);
        TinyAssert::count(1, self::feeLines());

        // The OLDER "remove" request lands late: no-op reporting the CURRENT
        // state, not stripping the line the newer click added.
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

        $newest = $module->syncTwoSurchargeCartLine($cart, false, 300);
        TinyAssert::true($newest['success']);
        TinyAssert::true($newest['changed']);
        TinyAssert::count(0, self::feeLines());
    }

    private static function testAuthoritativeSyncBypassesSequenceGuard(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        $module->syncTwoSurchargeCartLine($cart, true, 400);
        $module->syncTwoSurchargeCartLine($cart, false, 500);
        TinyAssert::count(0, self::feeLines());

        // The order-create self-heal passes NO seq: final authoritative sync
        // before charging, must apply whatever the stored sequence says.
        $selfHeal = $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true($selfHeal['success']);
        TinyAssert::true($selfHeal['changed']);
        TinyAssert::count(1, self::feeLines());
    }

    private static function testSequencedSyncFailsSoftWhenLockHeldByConcurrentRequest(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        // Lock held elsewhere: the sequenced call reports success=false
        // (frontend retries) and mutates nothing.
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

        // Creation lock held elsewhere: back off (0), the next sync retries.
        StubStore::$dbLocks['two_surcharge_product_create'] = true;
        $productsBefore = count(StubStore::$products);
        TinyAssert::same(0, $module->getTwoSurchargeCartProductId(true));
        TinyAssert::same($productsBefore, count(StubStore::$products), 'lock loser must not create a product');
        unset(StubStore::$dbLocks['two_surcharge_product_create']);

        // Concurrent winner wrote the id to the configuration TABLE while this
        // request's Configuration cache is stale (the stub's shared store
        // stands in for the DB read under the lock): the double-check must
        // adopt the winner's product instead of creating a duplicate.
        $winnerId = $module->getTwoSurchargeCartProductId(true);
        TinyAssert::true($winnerId > 0);
        $productsAfterWinner = count(StubStore::$products);
        TinyAssert::same($winnerId, $module->getTwoSurchargeCartProductId(true));
        TinyAssert::same($productsAfterWinner, count(StubStore::$products), 'no duplicate hidden product after the race');
    }

    /**
     * TWO-25071: the module assigns the MERCHANT's selected TaxRulesGroup to
     * the hidden product's id_tax_rules_group and never creates
     * Tax/TaxRulesGroup/TaxRule objects of its own.
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

        // Re-pointed selection: the next sync self-heals the product
        // assignment (no new objects, ever).
        StubStore::$taxRulesGroups[401] = ['name' => 'Reduced rate', 'active' => 1];
        StubStore::$taxRuleRates[401] = [self::COUNTRY_FR => 10.0];
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '401');
        Context::getContext()->cookie->two_payment_term = 60; // amount change forces a re-ensure
        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::same(401, (int) (new Product($productId))->id_tax_rules_group, 'selection change re-points the product on the next sync');
        TinyAssert::count(0, StubStore::$taxes);
        TinyAssert::count(0, StubStore::$taxRules);
        // Re-priced line applies the NEW group's FR rate (10%) to the 60-day
        // quote: 8.00 net -> 8.80 gross.
        $lines = self::feeLines();
        TinyAssert::count(1, $lines);
        TinyAssert::same(8.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(8.80, round((float) $lines[0]['total_wt'], 2));
    }

    /**
     * TWO-25071: destination-based rates. Payload line and PS cart line must
     * resolve the SAME rate for a covered, a stacked (6%+2%) and an uncovered
     * destination.
     */
    private static function testFeeTaxFollowsDestinationRulesOfSelectedGroup(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();

        // Covers FR at 25% and stacked 6%+2% for country 40; NO rule for 47.
        StubStore::$taxRuleRates[self::TAX_GROUP_ID] = [
            self::COUNTRY_FR => 25.0,
            40 => [6.0, 2.0],
        ];
        StubStore::$countries[40] = 'CA';
        StubStore::$addresses[8203] = ['id_country' => 40, 'loaded' => true] + StubStore::$addresses[8201];
        StubStore::$addresses[8204] = ['id_country' => 47, 'loaded' => true] + StubStore::$addresses[8201];

        // Covered (FR, 25%): 5.00 net -> 6.25 gross.
        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::same(6.25, round((float) self::feeLines()[0]['total_wt'], 2));
        $payloadLine = $module->buildTwoSurchargeLineItemForCart($cart, self::PRODUCT_GROSS);
        TinyAssert::same('0.25', $payloadLine['tax_rate']);
        TinyAssert::same('6.25', $payloadLine['gross_amount']);

        // Stacked (6% + 2% = 8%): both sides at 8%.
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

        // Uncovered (no rule): zero-rated on BOTH sides.
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
     * TWO-25071: the "No tax" sentinel (id 0) zeroes the fee tax for EVERY
     * destination via core's own untaxed-group semantics, no special-case code.
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

    /**
     * TWO-25071: vatnumber-module B2B exemption. Guards the hand-replicated
     * exemption condition in getTwoSurchargeTaxRateForCart against drifting
     * from core's Product::priceCalculation cart-line behaviour.
     */
    private static function testVatExemptB2BCartUntaxedOnBothCartLineAndPayload(): void
    {
        // Cross-border B2B: merchant NO (47), buyer FR with VAT number -> exempt.
        $module = self::makeModule();
        $cart = self::makeCart();
        Configuration::updateValue('VATNUMBER_MANAGEMENT', 1);
        Configuration::updateValue('VATNUMBER_COUNTRY', 47);
        StubStore::$addresses[8201]['vat_number'] = 'FR999999999';
        StubStore::$addresses[8202]['vat_number'] = 'FR999999999';
        // Core zeroes the tax on EVERY cart line for an exempt buyer, so the
        // fixture must report untaxed amounts: the relay fails loud on taxed
        // amounts under an exempt declaration.
        StubStore::$cartProducts[self::CART_ID][0]['total_wt'] = self::PRODUCT_NET;
        StubStore::$cartTotals[self::CART_ID] = [
            true => [Cart::ONLY_DISCOUNTS => 0.0, Cart::BOTH => self::PRODUCT_NET],
            false => [Cart::ONLY_DISCOUNTS => 0.0, Cart::BOTH => self::PRODUCT_NET],
            'average_products_tax_rate' => 0.0,
        ];

        $module->syncTwoSurchargeCartLine($cart, true);
        $lines = self::feeLines();
        TinyAssert::count(1, $lines);
        TinyAssert::same(5.00, round((float) $lines[0]['total'], 2));
        TinyAssert::same(5.00, round((float) $lines[0]['total_wt'], 2), 'VAT-exempt B2B cart must carry an UNTAXED PS fee line');
        $payloadLine = $module->buildTwoSurchargeLineItemForCart($cart, self::PRODUCT_GROSS);
        TinyAssert::same('0', $payloadLine['tax_rate'], 'payload side must apply the same B2B exemption as the cart line');
        TinyAssert::same('5.00', $payloadLine['gross_amount'], 'payload gross matches the untaxed PS line gross');

        // Domestic B2B (buyer country == VATNUMBER_COUNTRY): NOT exempt.
        // Fresh fixture so the re-price cannot be masked by sync idempotency.
        $module = self::makeModule();
        $cart = self::makeCart();
        Configuration::updateValue('VATNUMBER_MANAGEMENT', 1);
        Configuration::updateValue('VATNUMBER_COUNTRY', self::COUNTRY_FR);
        StubStore::$addresses[8201]['vat_number'] = 'FR999999999';
        StubStore::$addresses[8202]['vat_number'] = 'FR999999999';

        $module->syncTwoSurchargeCartLine($cart, true);
        $lines = self::feeLines();
        TinyAssert::count(1, $lines);
        TinyAssert::same(6.25, round((float) $lines[0]['total_wt'], 2), 'domestic B2B stays taxed on the PS line');
        $payloadLine = $module->buildTwoSurchargeLineItemForCart($cart, self::PRODUCT_GROSS);
        TinyAssert::same('0.25', $payloadLine['tax_rate'], 'domestic B2B stays taxed on the payload side');
        TinyAssert::same('6.25', $payloadLine['gross_amount']);
    }

    /* ---- order-level guard: no manual/duplicate fee rows (BO order edit) ---- */

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

        // Back-office order edit: must throw even as the FIRST row - this
        // product is module-managed only.
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

        // Front context, but the order ALREADY carries the fee row: a second
        // one is always a duplicate charge.
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

        // validateOrder creating the order's FIRST fee row in a front context.
        $frontController = new \stdClass();
        $frontController->controller_type = 'front';
        Context::getContext()->controller = $frontController;

        $module->hookActionObjectOrderDetailAddBefore([
            'object' => self::makeOrderDetailStub($feeProductId, 7004),
        ]);

        // Hidden product never created: the guard is inert for every product.
        StubStore::reset();
        $module->hookActionObjectOrderDetailAddBefore([
            'object' => self::makeOrderDetailStub(12345, 7005),
        ]);
    }

    /* ---- DB-level fee-guard trigger (the ACTUAL enforcement layer) ---- */

    /**
     * The stub Db has no SQL engine, so these tests can only assert the DDL
     * the module issues (shape, idempotence, drop). The trigger's REJECTION
     * semantics cannot be exercised by stubs and are verified against a live
     * PS8 + MariaDB container.
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

        $executedBefore = count(StubStore::$dbExecuted);
        TinyAssert::true($module->installTwoOrderDetailFeeGuardTrigger());
        TinyAssert::same($executedBefore, count(StubStore::$dbExecuted), 'no duplicate CREATE TRIGGER');

        $drop = new ReflectionMethod($module, 'dropTwoOrderDetailFeeGuardTrigger');
        $drop->setAccessible(true);
        $drop->invoke($module);
        TinyAssert::false(isset(StubStore::$dbTriggers[$triggerName]), 'trigger dropped at uninstall');
    }

    private static function testFeeGuardTriggerEnsuredLazilyOnCartSync(): void
    {
        // Upgrade path: install() never re-ran, so the first cart sync must
        // (re)install the trigger.
        $module = self::makeModule();
        $cart = self::makeCart();
        $triggerName = _DB_PREFIX_ . 'twopayment_fee_guard';
        TinyAssert::false(isset(StubStore::$dbTriggers[$triggerName]));

        $module->syncTwoSurchargeCartLine($cart, true);
        TinyAssert::true(isset(StubStore::$dbTriggers[$triggerName]), 'lazy ensure installs the trigger on first sync');

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

    /* ---- actionPresentCart: surcharge as its own totals row ---- */

    /**
     * Real PS8 core rows are ProductListingLazyArray OBJECTS (ArrayAccess),
     * never plain arrays - PresentedProductRowStub mirrors that shape.
     */
    private static function presentedProductsFixture(int $surchargeProductId): array
    {
        return [
            new PresentedProductRowStub(['id_product' => 9401, 'reference' => 'PLAIN-ITEM-REF', 'name' => 'Plain item', 'quantity' => 1]),
            new PresentedProductRowStub(['id_product' => $surchargeProductId, 'reference' => Twopayment::TWO_SURCHARGE_PRODUCT_REFERENCE, 'name' => 'Payment terms fee', 'quantity' => 1]),
        ];
    }

    private static function presentedSubtotalsFixture(Cart $cart, bool $includeTaxes, bool $withTaxRow): array
    {
        // Fixture cart carries no shipping cost, so Cart::BOTH === products-only here.
        $productsAmount = round((float) $cart->getOrderTotal($includeTaxes, Cart::BOTH), 2);
        $subtotals = [
            'products' => ['type' => 'products', 'label' => 'Subtotal', 'amount' => $productsAmount, 'value' => Tools::displayPrice($productsAmount)],
            'discounts' => null,
            'shipping' => ['type' => 'shipping', 'label' => 'Shipping', 'amount' => 0.0, 'value' => 'Free'],
        ];
        if ($withTaxRow) {
            $subtotals['tax'] = ['type' => 'tax', 'label' => 'Taxes', 'amount' => 1.23, 'value' => Tools::displayPrice(1.23)];
        }

        return $subtotals;
    }

    /**
     * The surcharge is a real hidden cart product; CartPresenter would
     * otherwise render it as an ordinary line item. Table covers both
     * price-display modes (PR #211) crossed with whether the shop's own
     * tax-breakdown subtotal row is present, since insertion must still
     * land immediately before 'shipping' either way.
     */
    private static function testActionPresentCartMovesSurchargeToOwnRowBeforeShipping(): void
    {
        $cases = [
            ['includeTaxes' => false, 'withTaxRow' => false, 'expectedAmount' => 5.00, 'why' => 'tax-excluded display, no tax row'],
            ['includeTaxes' => true, 'withTaxRow' => false, 'expectedAmount' => 6.25, 'why' => 'tax-included display, no tax row'],
            ['includeTaxes' => false, 'withTaxRow' => true, 'expectedAmount' => 5.00, 'why' => 'tax-excluded display, with tax row'],
            ['includeTaxes' => true, 'withTaxRow' => true, 'expectedAmount' => 6.25, 'why' => 'tax-included display, with tax row'],
        ];

        foreach ($cases as $case) {
            $module = self::makeModule();
            $cart = self::makeCart();
            StubStore::$groupPriceDisplayMethods = [1 => $case['includeTaxes'] ? 0 : 1];
            Context::getContext()->cookie->two_payment_term = 30;
            $module->syncTwoSurchargeCartLine($cart, true);
            $surchargeProductId = $module->getTwoSurchargeCartProductId(false);

            $productsBefore = self::presentedProductsFixture($surchargeProductId);
            $subtotalsBefore = self::presentedSubtotalsFixture($cart, $case['includeTaxes'], $case['withTaxRow']);
            $presented = new CartLazyArrayStub($cart, ['products' => $productsBefore, 'subtotals' => $subtotalsBefore]);

            $module->hookActionPresentCart(['presentedCart' => $presented]);
            $data = $presented->getData();

            $remainingProducts = array_values(array_filter($data['products'], static fn (PresentedProductRowStub $row): bool => (string) $row['reference'] === Twopayment::TWO_SURCHARGE_PRODUCT_REFERENCE));
            TinyAssert::count(0, $remainingProducts, 'surcharge row removed from products: ' . $case['why']);
            TinyAssert::count(1, $data['products'], 'plain item row untouched: ' . $case['why']);

            $keys = array_keys($data['subtotals']);
            $feeIndex = array_search('two_surcharge_fee', $keys, true);
            $shippingIndex = array_search('shipping', $keys, true);
            TinyAssert::same($shippingIndex - 1, $feeIndex, 'surcharge subtotal sits immediately before shipping: ' . $case['why']);

            $expectedProductsAmount = round($subtotalsBefore['products']['amount'] - $case['expectedAmount'], 2);
            TinyAssert::same($expectedProductsAmount, $data['subtotals']['products']['amount'], 'Subtotal reduced by exactly the surcharge amount: ' . $case['why']);
            TinyAssert::same(Tools::displayPrice($expectedProductsAmount), $data['subtotals']['products']['value'], 'Subtotal display value recomputed: ' . $case['why']);

            TinyAssert::same($case['expectedAmount'], $data['subtotals']['two_surcharge_fee']['amount'], 'surcharge row amount carries PR #211\'s display value through: ' . $case['why']);
            TinyAssert::same($module->getTwoSurchargeLineLabel(30), $data['subtotals']['two_surcharge_fee']['label'], 'surcharge row label: ' . $case['why']);

            if ($case['withTaxRow']) {
                TinyAssert::same(1.23, $data['subtotals']['tax']['amount'], 'untouched sibling subtotal: ' . $case['why']);
            }
            TinyAssert::same(null, $data['subtotals']['discounts'], 'untouched sibling subtotal: ' . $case['why']);
        }
    }

    private static function testActionPresentCartNoOpsWhenSurchargeNotSelected(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        // Two never selected: no cart line exists to move.
        $subtotals = self::presentedSubtotalsFixture($cart, false, false);
        $products = [new PresentedProductRowStub(['id_product' => 9401, 'reference' => 'PLAIN-ITEM-REF', 'name' => 'Plain item', 'quantity' => 1])];
        $presented = new CartLazyArrayStub($cart, ['products' => $products, 'subtotals' => $subtotals]);

        $module->hookActionPresentCart(['presentedCart' => $presented]);

        TinyAssert::same(['products' => $products, 'subtotals' => $subtotals], $presented->getData());
    }

    private static function testActionPresentCartNoOpsWhenRowAbsentFromPresentedProducts(): void
    {
        $module = self::makeModule();
        $cart = self::makeCart();
        $module->syncTwoSurchargeCartLine($cart, true);
        // Selected in the cart, but the presented list handed to the hook
        // never carried the row (e.g. a stale cache read) - nothing to move.
        $subtotals = self::presentedSubtotalsFixture($cart, false, false);
        $products = [new PresentedProductRowStub(['id_product' => 9401, 'reference' => 'PLAIN-ITEM-REF', 'name' => 'Plain item', 'quantity' => 1])];
        $presented = new CartLazyArrayStub($cart, ['products' => $products, 'subtotals' => $subtotals]);

        $module->hookActionPresentCart(['presentedCart' => $presented]);

        TinyAssert::same(['products' => $products, 'subtotals' => $subtotals], $presented->getData());
    }
}
