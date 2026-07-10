<?php

declare(strict_types=1);

/**
 * getTwoOfferedTermSurchargeAmounts() - the live per-term buyer surcharge
 * quote behind the checkout term chips (Magento parity:
 * Model/Webapi/Surcharges.php). Contract under test:
 *
 *  - Basis from the LIVE cart (getOrderTotal(true, Cart::BOTH)); one
 *    POST /v1/pricing/order/fee per offered term via fetchTwoTermFee.
 *  - {success: true, currency, amounts: {days: net-rounded-2dp}}.
 *  - Per-term degrade: a single term's quote failure zeroes THAT term only;
 *    even EVERY term failing on a nonzero basis still returns success:true
 *    with all-zero amounts (the JS hides zero fees per chip).
 *  - {success: false} ONLY for: surcharge disabled, no loaded cart, or a
 *    zero/empty cart basis - the JS then keeps the static rate preview.
 *  - Never throws: this sits behind a checkout-render AJAX call.
 */
final class TermSurchargeAmountsSpec
{
    public static function runAll(): void
    {
        self::testSuccessReturnsRoundedNetAmountPerOfferedTerm();
        self::testSingleTermFailureDegradesThatTermToZeroOnly();
        self::testSurchargeDisabledFailsWithoutWireCall();
        self::testMissingOrUnloadedCartFailsWithoutWireCall();
        self::testZeroCartBasisFailsWithoutWireCall();
        self::testTotalApiFailureWithNonzeroBasisStillSucceedsAllZero();
    }

    /**
     * Common fixture: percentage surcharge on offered terms 30 and 60, a
     * loaded EUR cart in context with a nonzero gross, ES invoice address.
     */
    private static function reset(float $cartGross = 250.0): void
    {
        StubStore::reset();
        // Fresh cookie: the fee-quote session cache must not leak across tests.
        Context::getContext()->cookie = new Cookie();
        Context::getContext()->cart = null;

        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_60', '3');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_60', 1);

        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[900] = ['id_country' => 34, 'loaded' => true];

        $cart = new Cart(7);
        $cart->id_currency = 978;
        $cart->id_address_invoice = 900;
        StubStore::$cartTotals[7][true][Cart::BOTH] = $cartGross;
        Context::getContext()->cart = $cart;
    }

    /**
     * Harness whose pricing API answers per term (keyed by
     * order_terms.duration_days), capturing every request.
     *
     * @param array<int,mixed> $responsesByDays
     */
    private static function moduleWithPerTermResponses(array $responsesByDays): object
    {
        return new class ($responsesByDays) extends TwopaymentTestHarness {
            public int $fetchCount = 0;
            public array $payloads = [];
            private array $responsesByDays;

            public function __construct(array $responsesByDays)
            {
                parent::__construct();
                $this->responsesByDays = $responsesByDays;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->fetchCount++;
                $this->payloads[] = $payload;
                $days = isset($payload['order_terms']['duration_days']) ? (int) $payload['order_terms']['duration_days'] : 0;
                return $this->responsesByDays[$days] ?? ['http_status' => 500];
            }
        };
    }

    private static function okQuote(string $net): array
    {
        return [
            'http_status' => 200,
            'buyer_fee_share' => $net,
            'total_fee_tax_rate' => '0.21',
            'currency' => 'EUR',
        ];
    }

    private static function testSuccessReturnsRoundedNetAmountPerOfferedTerm(): void
    {
        self::reset();
        $module = self::moduleWithPerTermResponses([
            30 => self::okQuote('5.00'),
            // Must round to 2dp in the response map.
            60 => self::okQuote('7.505'),
        ]);

        $result = $module->getTwoOfferedTermSurchargeAmounts();

        TinyAssert::true($result['success']);
        TinyAssert::same('EUR', $result['currency']);
        TinyAssert::same([30 => 5.0, 60 => 7.51], $result['amounts']);
        TinyAssert::same(2, $module->fetchCount, 'one pricing call per offered term');
        // The basis must be the live cart gross (products + shipping, tax
        // incl.), sent to every per-term quote.
        TinyAssert::same('250.00', (string) $module->payloads[0]['gross_amount']);
        TinyAssert::same('ES', $module->payloads[0]['buyer_country_code']);
        TinyAssert::same('EUR', $module->payloads[0]['currency']);
    }

    private static function testSingleTermFailureDegradesThatTermToZeroOnly(): void
    {
        self::reset();
        $module = self::moduleWithPerTermResponses([
            30 => self::okQuote('5.00'),
            60 => ['http_status' => 500, 'error' => 'boom'],
        ]);

        $result = $module->getTwoOfferedTermSurchargeAmounts();

        TinyAssert::true($result['success'], 'a single term failure must not fail the whole response');
        TinyAssert::same([30 => 5.0, 60 => 0.0], $result['amounts']);
    }

    private static function testSurchargeDisabledFailsWithoutWireCall(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'none');
        $module = self::moduleWithPerTermResponses([30 => self::okQuote('5.00')]);

        TinyAssert::same(['success' => false], $module->getTwoOfferedTermSurchargeAmounts());
        TinyAssert::same(0, $module->fetchCount);
    }

    private static function testMissingOrUnloadedCartFailsWithoutWireCall(): void
    {
        self::reset();
        $module = self::moduleWithPerTermResponses([30 => self::okQuote('5.00')]);

        Context::getContext()->cart = null;
        TinyAssert::same(['success' => false], $module->getTwoOfferedTermSurchargeAmounts());

        $unloaded = new Cart(7);
        $unloaded->loaded = false;
        Context::getContext()->cart = $unloaded;
        $module->resetTwoFeeCache();
        TinyAssert::same(['success' => false], $module->getTwoOfferedTermSurchargeAmounts());

        TinyAssert::same(0, $module->fetchCount);
    }

    private static function testZeroCartBasisFailsWithoutWireCall(): void
    {
        // Empty cart / anonymous probe: gross basis is 0 - the JS falls back
        // to the static percentage preview entirely.
        self::reset(0.0);
        $module = self::moduleWithPerTermResponses([30 => self::okQuote('5.00')]);

        TinyAssert::same(['success' => false], $module->getTwoOfferedTermSurchargeAmounts());
        TinyAssert::same(0, $module->fetchCount);
    }

    private static function testTotalApiFailureWithNonzeroBasisStillSucceedsAllZero(): void
    {
        // Distinct from the zero-basis case above: a nonzero basis where
        // EVERY quote fails is a per-term degrade, NOT a fallback signal.
        self::reset();
        $module = self::moduleWithPerTermResponses([
            30 => ['http_status' => 500],
            60 => ['http_status' => 500],
        ]);

        $result = $module->getTwoOfferedTermSurchargeAmounts();

        TinyAssert::true($result['success']);
        TinyAssert::same('EUR', $result['currency']);
        TinyAssert::same([30 => 0.0, 60 => 0.0], $result['amounts']);
        TinyAssert::same(2, $module->fetchCount, 'every term must still be attempted');
    }
}
