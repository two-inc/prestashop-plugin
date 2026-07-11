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
 * - tax: PrestaShop applies exactly getTwoSurchargeTaxRate() to the line via
 *   the module-managed Tax/TaxRulesGroup/TaxRule graph.
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
    }

    /* ---- fixtures ---- */

    private static function makeModule(array $feePerDays = [30 => '5.00']): Twopayment
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '5');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_60', '8');
        Configuration::updateValue('PS_TWO_SURCHARGE_TAX_RATE', '25');
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
        // Net = quoted buyer_fee_share (5.00); gross applies the ADMIN
        // configured 25% surcharge tax rate through the tax rules group.
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
