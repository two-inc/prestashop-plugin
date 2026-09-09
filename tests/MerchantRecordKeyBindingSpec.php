<?php

declare(strict_types=1);

// ABN-530 - the cached merchant record is bound to the API key it was fetched for, so a shop whose
// own key row differs from the tier holding the record reads a cold cache rather than another
// merchant's record.
final class MerchantRecordKeyBindingSpec
{
    private const RECORD = [
        'http_status' => 200,
        'id' => 'mid',
        'available_terms' => [14, 30],
        'due_in_days' => 30,
        'invoice_distributed_by_merchant' => true,
        'min_order_amount' => 100,
        'min_order_currency' => 'EUR',
        'min_order_basis' => 'net',
        'supported_buyer_countries' => ['NO'],
    ];

    public static function runAll(): void
    {
        self::testAKeySwapUnderTheRecordReadsAsCold();
        self::testAShopWithItsOwnKeyDoesNotReadAWiderTiersRecord();
        self::testAnUnstampedRecordIsStillServed();
        self::testAMismatchedStampRefetchesInsideTheTtl();
    }

    /**
     * @param array<string,mixed> $response
     */
    private static function module(array $response = self::RECORD): object
    {
        return new class ($response) extends TwopaymentTestHarness {
            public int $calls = 0;

            /** @var array<string,mixed> */
            private array $response;

            /** @param array<string,mixed> $response */
            public function __construct(array $response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                ++$this->calls;
                return $this->response;
            }
        };
    }

    /** A record cached by the production write path, stamped with $key's identity. */
    private static function primeRecordForKey(object $module, string $key): void
    {
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'mid');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', $key);
        $module->getMerchantAvailableTerms(true);
    }

    /**
     * Each slot the one merchant-record fetch feeds, as [reader, value when resolved, value when the
     * record belongs to another key].
     *
     * @return array<int,array{0:callable(object):mixed,1:mixed,2:mixed,3:string}>
     */
    private static function slots(): array
    {
        return [
            [static fn (object $m) => $m->getMerchantAvailableTerms(), [14, 30], [], 'offerable term list'],
            [static fn (object $m) => $m->getMerchantDueInDays(), 30, null, 'default term'],
            [static fn (object $m) => $m->isMerchantInvoiceDistributed(), true, false, 'invoice-upload entitlement'],
            [
                static fn (object $m) => $m->getPlatformMinimumOrder(),
                ['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'net'],
                null,
                'platform minimum order',
            ],
            [
                static fn (object $m) => $m->getTwoBuyerCountryRestrictionState(),
                Twopayment::BUYER_COUNTRIES_ALLOWLIST,
                Twopayment::BUYER_COUNTRIES_ABSENT,
                'buyer-country allowlist state',
            ],
        ];
    }

    /**
     * Given a record cached for one key, When the stored key becomes another one by a route that
     * never invalidates (direct SQL, an upgrade script, a wider tier), Then every slot degrades to
     * its own unresolved answer.
     */
    private static function testAKeySwapUnderTheRecordReadsAsCold(): void
    {
        foreach (self::slots() as [$read, $resolved, $unresolved, $description]) {
            StubStore::reset();
            $module = self::module();
            self::primeRecordForKey($module, 'key-a');

            TinyAssert::same($resolved, $read($module), 'served for its own key: ' . $description);

            Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-b');

            TinyAssert::same($unresolved, $read($module), 'withheld from another key: ' . $description);
        }
    }

    /**
     * Given a global-tier record and a shop holding its own API key row, When that shop reads the
     * record, Then it gets nothing - the tier that holds the record does not decide whose record it
     * is. A sibling shop with no key row of its own resolves the same key and still reads it.
     */
    private static function testAShopWithItsOwnKeyDoesNotReadAWiderTiersRecord(): void
    {
        foreach (self::slots() as [$read, $resolved, $unresolved, $description]) {
            StubStore::reset();
            $module = self::module();
            self::primeRecordForKey($module, 'global-key');

            StubStore::$multistore = true;
            StubStore::$shops = [1 => 1, 2 => 2];

            Shop::setContext(Shop::CONTEXT_SHOP, 2);
            Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'shop-2-key');

            TinyAssert::same($unresolved, $read($module), 'shop with its own key: ' . $description);

            Shop::setContext(Shop::CONTEXT_SHOP, 1);

            TinyAssert::same($resolved, $read($module), 'shop inheriting the global key: ' . $description);
        }
    }

    /**
     * Given a record cached before the identity stamp existed, When it is read, Then it is served -
     * an upgrade must not withhold Two until the next refresh.
     */
    private static function testAnUnstampedRecordIsStillServed(): void
    {
        foreach (self::slots() as [$read, $resolved, , $description]) {
            StubStore::reset();
            $module = self::module();
            self::primeRecordForKey($module, 'key-a');
            Configuration::updateValue(Twopayment::CONFIG_MERCHANT_RECORD_KEY, '');

            TinyAssert::same($resolved, $read($module), 'unstamped record: ' . $description);
        }
    }

    /**
     * Given a fresh timestamp written by another key's refresh, When this key asks for the record,
     * Then the fetch runs anyway - a mismatch is a cold cache, not a young one.
     */
    private static function testAMismatchedStampRefetchesInsideTheTtl(): void
    {
        $cases = [
            ['key-a', 1, 'the same key rides the shared clock'],
            ['key-b', 2, 'a different key refetches under it'],
        ];

        foreach ($cases as [$key, $expectedCalls, $description]) {
            StubStore::reset();
            $module = self::module();
            self::primeRecordForKey($module, 'key-a');

            Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', $key);
            $module->getMerchantAvailableTerms(true);

            TinyAssert::same($expectedCalls, $module->calls, 'wire calls: ' . $description);
        }
    }
}
