<?php

declare(strict_types=1);

// The merchant record is fetched for the merchant the stored API key belongs to.
// PS_TWO_MERCHANT_ID is written only by a verified General save, so a shop seeded,
// cloned or migrated without one holds an empty or stale id, which Two answers
// with 404. The refresh then resolves the id from the key, fetches that record and
// stores the resolved id and short name; a healthy shop pays no extra call.
final class MerchantIdResolutionSpec
{
    private const KEY = 'secret-key-value';

    public static function runAll(): void
    {
        self::testTheRecordIsFetchedForTheKeysMerchant();
        self::testAnEmptyShortNameIsRepairedOnce();
        self::testAKeyChangedMidRefreshWritesNothing();
        self::testTheVerifyCarriesTheCallersTimeout();
    }

    /**
     * @param string|null $keyMerchant the merchant verify_api_key answers with; null is a rejected key
     * @param string      $keyShort    the short name it answers with; '' leaves the field out
     * @param string[]    $records     merchant ids GET /v1/merchant/<id> answers 200 for; any other id is a 404
     */
    private static function harness(string $storedId, string $storedShort, ?string $keyMerchant, string $keyShort, array $records): object
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_ID', $storedId);
        Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', $storedShort);
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', self::KEY);
        Configuration::deleteByName(Twopayment::CONFIG_MERCHANT_COUNTRY);

        return new class ($keyMerchant, $keyShort, $records) extends TwopaymentTestHarness {
            /** @var string[] Wire calls in order: "verify" or the record endpoint. */
            public array $calls = array();
            /** @var array<int,int|null> Timeout each verify call was given. */
            public array $verifyTimeouts = array();
            /** @var callable|null Runs inside the verify call, i.e. mid-flight. */
            public $duringVerify = null;
            private ?string $keyMerchant;
            private string $keyShort;
            /** @var string[] */
            private array $records;

            public function __construct(?string $keyMerchant, string $keyShort, array $records)
            {
                parent::__construct();
                Configuration::deleteByName(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS);
                $this->merchant_short_name = Configuration::get('PS_TWO_MERCHANT_SHORT_NAME');
                $this->keyMerchant = $keyMerchant;
                $this->keyShort = $keyShort;
                $this->records = $records;
            }

            protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
            {
                $this->calls[] = 'verify';
                $this->verifyTimeouts[] = $timeout;
                if (is_callable($this->duringVerify)) {
                    call_user_func($this->duringVerify);
                }

                return $this->keyMerchant === null
                    ? array('response' => '{"error":"unauthorized"}', 'code' => 401, 'error' => '')
                    : array('response' => json_encode(array_filter(array('id' => $this->keyMerchant, 'short_name' => $this->keyShort))), 'code' => 200, 'error' => '');
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->calls[] = $endpoint;
                $id = substr((string) $endpoint, strlen('/v1/merchant/'));
                if (!in_array($id, $this->records, true)) {
                    return array('http_status' => 404, 'error' => 'merchant not found');
                }

                return array('http_status' => 200, 'id' => $id, 'available_terms' => array(30, 60), 'due_in_days' => 30, 'country_code' => 'no');
            }

            public function refreshTwoFxRates()
            {
                return false;
            }
        };
    }

    /** Once the short name is repaired, the next refresh is back to one call. */
    private static function testAnEmptyShortNameIsRepairedOnce(): void
    {
        $module = self::harness('m-key', '', 'm-key', 'shop', array('m-key'));

        $module->refreshMerchantRecord();
        $module->calls = array();
        TinyAssert::true($module->refreshMerchantRecord(), 'the second refresh succeeds');
        TinyAssert::same(array('/v1/merchant/m-key'), $module->calls, 'the second refresh does not verify');
    }

    /** An admin save storing another key while the verify is out: nothing is written for the old key's merchant. */
    private static function testAKeyChangedMidRefreshWritesNothing(): void
    {
        $module = self::harness('m-old', 'old-shop', 'm-key', 'shop', array('m-key'));
        $module->duringVerify = static function () {
            Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'a-newly-saved-key');
        };

        TinyAssert::false($module->refreshMerchantRecord(), 'a key changed mid-refresh is a failed refresh');
        TinyAssert::same(array('/v1/merchant/m-old', 'verify'), $module->calls, 'nothing is fetched for the old key\'s merchant');
        TinyAssert::same('m-old', (string) Configuration::get('PS_TWO_MERCHANT_ID'), 'the stored id is not written');
        TinyAssert::same('old-shop', (string) Configuration::get('PS_TWO_MERCHANT_SHORT_NAME'), 'the stored short name is not written');
    }

    /** The front read path's tight cap applies to the verify too, not the 10 s default. */
    private static function testTheVerifyCarriesTheCallersTimeout(): void
    {
        $module = self::harness('m-old', 'old-shop', 'm-key', 'shop', array('m-key'));

        $module->refreshMerchantRecord(Twopayment::MERCHANT_RECORD_STALE_TIMEOUT);

        TinyAssert::same(array(Twopayment::MERCHANT_RECORD_STALE_TIMEOUT), $module->verifyTimeouts, 'the verify gets the caller\'s cap');
    }

    private static function testTheRecordIsFetchedForTheKeysMerchant(): void
    {
        $record = array('30', '60');
        $cases = array(
            // stored id, stored short name, key's merchant, key's short name, record ids that exist, refreshed?, id after, short name after, wire calls, description
            array('m-key', 'shop', 'm-key', 'shop', array('m-key'), true, 'm-key', 'shop', array('/v1/merchant/m-key'), 'a correct id costs one call and no verification'),
            array('m-key', '', 'm-key', 'shop', array('m-key'), true, 'm-key', 'shop', array('/v1/merchant/m-key', 'verify'), 'a correct id with no short name verifies once and stores it, with no refetch'),
            array('m-old', 'old-shop', 'm-key', 'shop', array('m-key'), true, 'm-key', 'shop', array('/v1/merchant/m-old', 'verify', '/v1/merchant/m-key'), 'a stale id is resolved from the key, fetched and stored, with its short name'),
            array('', '', 'm-key', 'shop', array('m-key'), true, 'm-key', 'shop', array('verify', '/v1/merchant/m-key'), 'an empty id and short name are resolved from the key and stored'),
            array('m-old', 'old-shop', 'm-key', '', array('m-key'), true, 'm-key', 'old-shop', array('/v1/merchant/m-old', 'verify', '/v1/merchant/m-key'), 'a key answering no short name keeps the stored one'),
            array('m-old', 'old-shop', null, '', array('m-key'), false, 'm-old', 'old-shop', array('/v1/merchant/m-old', 'verify'), 'a key that does not verify leaves the stored id and short name and fetches nothing more'),
            array('m-key', '', 'm-key', 'shop', array(), false, 'm-key', 'shop', array('/v1/merchant/m-key', 'verify'), 'a 404 for the key\'s own merchant is a failed refresh, not a loop; the short name still lands'),
        );

        foreach ($cases as [$storedId, $storedShort, $keyMerchant, $keyShort, $records, $refreshed, $storedAfter, $shortAfter, $calls, $description]) {
            $module = self::harness($storedId, $storedShort, $keyMerchant, $keyShort, $records);

            $result = $module->refreshMerchantRecord();

            TinyAssert::same($refreshed, $result, $description . ': refreshed?');
            TinyAssert::same($calls, $module->calls, $description . ': wire calls');
            TinyAssert::same($storedAfter, (string) Configuration::get('PS_TWO_MERCHANT_ID'), $description . ': stored id');
            TinyAssert::same($shortAfter, (string) Configuration::get('PS_TWO_MERCHANT_SHORT_NAME'), $description . ': stored short name');
            TinyAssert::same($shortAfter, (string) $module->merchant_short_name, $description . ': the short name the checkout guard reads');
            TinyAssert::same($refreshed ? $record : array(), array_map('strval', (array) json_decode((string) Configuration::get(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS), true)), $description . ': offered terms');
            TinyAssert::same($refreshed ? 'NO' : '', (string) Configuration::get(Twopayment::CONFIG_MERCHANT_COUNTRY), $description . ': merchant country');
            $logs = implode("\n", array_column(PrestaShopLogger::$logs, 'message'));
            TinyAssert::false(strpos($logs, self::KEY) !== false, $description . ': the API key is never logged');
            if (!$refreshed) {
                TinyAssert::true(strpos($logs, 'Merchant record') !== false, $description . ': the failure is logged');
            }
        }
    }
}
