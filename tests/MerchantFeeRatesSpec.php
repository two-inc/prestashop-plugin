<?php

declare(strict_types=1);

/**
 * fetchTwoMerchantFeeRates() - the merchant-fee lookup behind the inline fee
 * display on the admin "Available Payment Terms" checkboxes (Magento parity:
 * Controller/Adminhtml/Config/Fees.php). Contract under test:
 *
 *  - POST /pricing/v1/merchant/rates with {buyer_country_code,
 *    recourse_pricing: false, net_terms: int[]} on a tight render-path
 *    timeout.
 *  - Response normalised to {success, currency, fees: {"<days>":
 *    {percentage, fixed}}}.
 *  - Fail-soft: missing API key, empty term list, non-200, or malformed body
 *    ALL return {success: false} - never throws, so the admin page never
 *    breaks on an API outage.
 */
final class MerchantFeeRatesSpec
{
    public static function runAll(): void
    {
        self::testSuccessNormalisesRatesAndSendsExpectedRequest();
        self::testBuyerCountryFallsBackToNlWhenNoDefaultCountry();
        self::testMissingApiKeyFailsWithoutWireCall();
        self::testEmptyOrInvalidTermsFailWithoutWireCall();
        self::testNon200Fails();
        self::testMalformedBodyFails();
        self::testMalformedRateRowsAreSkipped();
        self::testAbsentCurrencyNormalisesToEmptyString();
    }

    /**
     * Harness with a stubbed, capturing setTwoPaymentRequest.
     *
     * @param mixed $response
     */
    private static function moduleWithRatesResponse($response): object
    {
        return new class ($response) extends TwopaymentTestHarness {
            public int $fetchCount = 0;
            public $lastEndpoint = null;
            public $lastPayload = null;
            public $lastMethod = null;
            public $lastTimeout = null;
            private $response;

            public function __construct($response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->fetchCount++;
                $this->lastEndpoint = $endpoint;
                $this->lastPayload = $payload;
                $this->lastMethod = $method;
                $this->lastTimeout = $timeout;
                return $this->response;
            }
        };
    }

    private static function configureMerchantIdentity(): void
    {
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-123');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
    }

    private static function okResponse(): array
    {
        return [
            'http_status' => 200,
            'currency' => 'NOK',
            'rates' => [
                // The API sends numeric values as strings.
                ['net_terms' => '15', 'percentage_fee' => '1.50', 'fixed_fee' => '0.00'],
                ['net_terms' => 30, 'percentage_fee' => '2.51', 'fixed_fee' => '0.10'],
            ],
        ];
    }

    private static function testSuccessNormalisesRatesAndSendsExpectedRequest(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // StubStore country map: 47 => 'NO'.
        Configuration::updateValue('PS_COUNTRY_DEFAULT', 47);

        $module = self::moduleWithRatesResponse(self::okResponse());
        // Unsorted, duplicated, mixed-type input must normalise to [15, 30].
        $result = $module->fetchTwoMerchantFeeRates([30, '15', 30]);

        TinyAssert::same('/pricing/v1/merchant/rates', $module->lastEndpoint);
        TinyAssert::same('POST', $module->lastMethod);
        TinyAssert::same(Twopayment::API_TIMEOUT_STATE_CHECK, $module->lastTimeout, 'render-path call must use the tight timeout');
        TinyAssert::same('NO', $module->lastPayload['buyer_country_code']);
        TinyAssert::false($module->lastPayload['recourse_pricing']);
        TinyAssert::same([15, 30], $module->lastPayload['net_terms']);

        TinyAssert::true($result['success']);
        TinyAssert::same('NOK', $result['currency']);
        TinyAssert::same(['percentage' => 1.5, 'fixed' => 0.0], $result['fees']['15']);
        TinyAssert::same(['percentage' => 2.51, 'fixed' => 0.1], $result['fees']['30']);
    }

    private static function testBuyerCountryFallsBackToNlWhenNoDefaultCountry(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // No PS_COUNTRY_DEFAULT configured at all.

        $module = self::moduleWithRatesResponse(self::okResponse());
        $result = $module->fetchTwoMerchantFeeRates([30]);

        TinyAssert::same('NL', $module->lastPayload['buyer_country_code']);
        TinyAssert::true($result['success']);

        // Configured but unresolvable country id also falls back.
        StubStore::reset();
        self::configureMerchantIdentity();
        Configuration::updateValue('PS_COUNTRY_DEFAULT', 999);
        $module = self::moduleWithRatesResponse(self::okResponse());
        $module->fetchTwoMerchantFeeRates([30]);
        TinyAssert::same('NL', $module->lastPayload['buyer_country_code']);
    }

    private static function testMissingApiKeyFailsWithoutWireCall(): void
    {
        StubStore::reset();
        // No PS_TWO_MERCHANT_API_KEY configured.

        $module = self::moduleWithRatesResponse(self::okResponse());
        $result = $module->fetchTwoMerchantFeeRates([30]);

        TinyAssert::false($result['success']);
        TinyAssert::same(0, $module->fetchCount, 'must not hit the wire without an API key');
    }

    private static function testEmptyOrInvalidTermsFailWithoutWireCall(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        $module = self::moduleWithRatesResponse(self::okResponse());

        TinyAssert::false($module->fetchTwoMerchantFeeRates([])['success']);
        // Non-numeric / non-positive entries normalise away to an empty set.
        TinyAssert::false($module->fetchTwoMerchantFeeRates(['abc', -5, 0, null, [7]])['success']);
        TinyAssert::same(0, $module->fetchCount, 'must not hit the wire with no valid terms');
    }

    private static function testNon200Fails(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        foreach ([0, 401, 500] as $status) {
            $module = self::moduleWithRatesResponse(['http_status' => $status, 'rates' => []]);
            TinyAssert::false($module->fetchTwoMerchantFeeRates([30])['success'], 'HTTP ' . $status . ' must fail soft');
            TinyAssert::same(1, $module->fetchCount);
        }
    }

    private static function testMalformedBodyFails(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        $malformed = [
            ['http_status' => 200], // no rates key
            ['http_status' => 200, 'rates' => 'oops'], // rates not an array
            'not-an-array-at-all',
            null,
        ];
        foreach ($malformed as $response) {
            $module = self::moduleWithRatesResponse($response);
            TinyAssert::false($module->fetchTwoMerchantFeeRates([30])['success'], 'malformed body must fail soft');
        }
    }

    private static function testMalformedRateRowsAreSkipped(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        $module = self::moduleWithRatesResponse([
            'http_status' => 200,
            'currency' => 'EUR',
            'rates' => [
                'not-a-row',
                ['percentage_fee' => '9.99'], // no net_terms
                ['net_terms' => 'soon'], // non-numeric net_terms
                ['net_terms' => -30, 'percentage_fee' => '9.99'], // non-positive
                ['net_terms' => 30], // valid but fee fields absent -> zeros
                ['net_terms' => 60, 'percentage_fee' => 'oops', 'fixed_fee' => '1.25'], // non-numeric fee -> zero
            ],
        ]);
        $result = $module->fetchTwoMerchantFeeRates([30, 60]);

        TinyAssert::true($result['success']);
        TinyAssert::count(2, $result['fees']);
        TinyAssert::same(['percentage' => 0.0, 'fixed' => 0.0], $result['fees']['30']);
        TinyAssert::same(['percentage' => 0.0, 'fixed' => 1.25], $result['fees']['60']);
    }

    private static function testAbsentCurrencyNormalisesToEmptyString(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        $module = self::moduleWithRatesResponse([
            'http_status' => 200,
            'rates' => [['net_terms' => 30, 'percentage_fee' => '1.00', 'fixed_fee' => '0.00']],
        ]);
        $result = $module->fetchTwoMerchantFeeRates([30]);

        TinyAssert::true($result['success']);
        TinyAssert::same('', $result['currency'], 'absent currency must normalise to empty string, not be invented');
    }
}
