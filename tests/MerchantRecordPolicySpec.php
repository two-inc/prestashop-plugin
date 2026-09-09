<?php

declare(strict_types=1);

// ABN-519. The cached merchant record never expires and is never evicted; only
// the API-key check verdict may hide the payment tile.
final class MerchantRecordPolicySpec
{
    public static function runAll(): void
    {
        self::testNoFailurePathEvictsTheRecord();
        self::testAStaleRecordIsServedAfterStandingInForTheSchedule();
        self::testAStaleRecordStandsInAtMostOncePerInterval();
        self::testOnlyTheKeyVerdictHidesTheTile();
        self::testTheScheduledRouteRefusesEveryTokenButTheStoredOne();
        self::testTheScheduledRouteThrottlesAcceptedRuns();
        self::testAScheduledRunClearsTheStandInMark();
        self::testARecordWithNoSuccessStampHasNothingToServe();
        self::testARecordStampedForAnotherKeyIsNothingToServe();
        self::testAKeyChangeDuringAnOutageIsRecoverableByRevertingTheKey();
        self::testASuccessfulRefreshClearsTheForeignFloor();
        self::testAFailingScheduleIsNotReportedAsAScheduleThatNeverRan();
        self::testTheDiagnosticsRowsFollowTheContextTheRecordLivesIn();
    }

    /** The record as a fetch would have left it, with a stamp $age seconds old. */
    private static function seedHeldRecord(int $age): void
    {
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, json_encode(array(30, 60)));
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_DUE_IN_DAYS, 60);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, '1');
        Configuration::updateValue(Twopayment::CONFIG_PLATFORM_MIN_ORDER, json_encode(
            array('amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net')
        ));
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES, json_encode(array('GB')));
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, time() - $age);
    }

    /** @return array<string,string|false> Every cached value the record feeds. */
    private static function heldValues(): array
    {
        $values = array();
        foreach (array(
            Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS,
            Twopayment::CONFIG_MERCHANT_DUE_IN_DAYS,
            Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED,
            Twopayment::CONFIG_PLATFORM_MIN_ORDER,
            Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES,
        ) as $key) {
            $values[$key] = (string) Configuration::get($key);
        }

        return $values;
    }

    /**
     * @param array<int,array<string,mixed>> $responses
     */
    private static function harness(array $responses = array()): object
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'mid');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key');

        return new class ($responses) extends TwopaymentTestHarness {
            public int $calls = 0;
            /** @var array<int,int> Wire cap each call was given. */
            public array $timeouts = array();
            /** @var array<int,array<string,mixed>> */
            private array $responses;

            public function __construct(array $responses)
            {
                parent::__construct();
                $this->responses = $responses;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                ++$this->calls;
                $this->timeouts[] = (int) $timeout;
                if (is_callable($this->onRequest)) {
                    return call_user_func($this->onRequest);
                }
                $next = array_shift($this->responses);

                return $next === null ? array('http_status' => 0) : $next;
            }

            /** @var callable|null Runs in place of the queued response. */
            public $onRequest = null;

            public function refreshTwoFxRates()
            {
                return false;
            }

            public function isTwoSingleShopContextForTest(): bool
            {
                return $this->isTwoSingleShopContext();
            }

            public function merchantRecordStatusLineForTest(): string
            {
                return $this->getTwoMerchantRecordStatusLine();
            }

            /** @return array<string,string> Diagnostics row name => rendered html_content. */
            public function diagnosticsRowHtmlForTest(): array
            {
                $html = array();
                foreach ($this->getTwoDiagnosticsForm()['form']['input'] as $input) {
                    if (isset($input['html_content'])) {
                        $html[(string) $input['name']] = (string) $input['html_content'];
                    }
                }

                return $html;
            }

            /** @var array<string,mixed>|null Payload the refresh endpoint answered with. */
            public $refreshResponse = null;

            protected function respondTwoRefreshMerchantRecord(array $payload)
            {
                $this->refreshResponse = $payload;
            }
        };
    }

    /**
     * Given a held record, When a refresh fails for any reason, Then nothing
     * cached is touched: a Cloudflare outage must not make a merchant's admin
     * page forget the terms and fees it was showing.
     */
    private static function testNoFailurePathEvictsTheRecord(): void
    {
        $cases = array(
            array(array('http_status' => 0), 'an unreachable API'),
            array(array('http_status' => 401), 'a rejected key'),
            array(array('http_status' => 403), 'a forbidden key'),
            array(array('http_status' => 429), 'a rate-limited shop'),
            array(array('http_status' => 500), 'a service error'),
            array(array('http_status' => 502), 'a bad gateway'),
            array(array('http_status' => 200, 'detail' => 'ok'), 'a 200 that is not the merchant record'),
            array(array('http_status' => 200), 'a 200 with no body at all'),
        );

        foreach ($cases as list($response, $description)) {
            $module = self::harness(array($response));
            self::seedHeldRecord(10);
            $expected = self::heldValues();

            TinyAssert::false($module->refreshMerchantRecord(), 'refresh outcome: ' . $description);

            TinyAssert::same(1, $module->calls, 'wire calls: ' . $description);
            TinyAssert::same($expected, self::heldValues(), 'the record must survive ' . $description);
        }
    }

    /**
     * Given a record older than the staleness window, When it is read, Then the
     * read attempts one refresh on a tight cap and serves what it holds either way.
     */
    private static function testAStaleRecordIsServedAfterStandingInForTheSchedule(): void
    {
        $cases = array(
            array(array('http_status' => 0), array(30, 60), 'a failed stand-in serves the held record'),
            array(array('http_status' => 200, 'available_terms' => array(7)), array(7), 'a successful stand-in serves the new record'),
        );

        foreach ($cases as list($response, $expectedTerms, $description)) {
            $module = self::harness(array($response));
            self::seedHeldRecord(Twopayment::MERCHANT_RECORD_STALE_AFTER + 1);

            TinyAssert::same($expectedTerms, $module->getMerchantAvailableTerms(), 'terms: ' . $description);
            TinyAssert::same(1, $module->calls, 'wire calls: ' . $description);
            TinyAssert::same(
                array(Twopayment::MERCHANT_RECORD_STALE_TIMEOUT),
                $module->timeouts,
                'a stand-in a page render pays for must be capped: ' . $description
            );
            TinyAssert::true(
                (int) Configuration::get(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS) > 0,
                'a stand-in must be recorded for the admin: ' . $description
            );
        }
    }

    /**
     * Given a schedule that is not running, When the record is read repeatedly,
     * Then at most one stand-in per interval reaches the wire, and the mark the
     * admin surface dates keeps the FIRST stand-in's time.
     */
    private static function testAStaleRecordStandsInAtMostOncePerInterval(): void
    {
        $interval = Twopayment::MERCHANT_RECORD_STALE_REFRESH_INTERVAL;
        $stale = Twopayment::MERCHANT_RECORD_STALE_AFTER + 1;

        // [seconds since the last stand-in, expected wire calls, description].
        $cases = array(
            array(null, 1, 'the first read of a stale record stands in'),
            array(0, 0, 'a read moments later does not'),
            array($interval - 1, 0, 'a read just inside the interval does not'),
            array($interval + 1, 1, 'a read past the interval stands in again'),
        );

        foreach ($cases as list($since, $expectedCalls, $description)) {
            $module = self::harness(array(array('http_status' => 0)));
            self::seedHeldRecord($stale);
            if ($since !== null) {
                Configuration::updateValue(
                    Twopayment::CONFIG_MERCHANT_RECORD_STALE_COOLDOWN_TS,
                    time() - $since
                );
            }

            $module->getMerchantAvailableTerms();

            TinyAssert::same($expectedCalls, $module->calls, 'wire calls: ' . $description);
        }

        // Two stand-ins an interval apart: the mark stays on the first.
        $module = self::harness(array(array('http_status' => 0), array('http_status' => 0)));
        self::seedHeldRecord($stale);
        $module->getMerchantAvailableTerms();
        $first = (int) Configuration::get(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS);
        Configuration::updateValue(
            Twopayment::CONFIG_MERCHANT_RECORD_STALE_COOLDOWN_TS,
            time() - $interval - 1
        );
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS, $first - $interval);
        $module->getMerchantAvailableTerms();

        TinyAssert::same(2, $module->calls, 'a second stand-in an interval later reaches the wire');
        TinyAssert::same(
            $first - $interval,
            (int) Configuration::get(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS),
            'a later stand-in must not refresh the mark the admin surface dates'
        );
    }

    /**
     * Given a verified key, When the merchant record cannot be resolved, Then the
     * tile is still offered: only the key verdict may withhold it (ABN-519), and
     * only its definitive-rejection categories do (ABN-533). The record read runs
     * first in each case, so a failing fetch is what the tile decision is
     * actually made over.
     */
    private static function testOnlyTheKeyVerdictHidesTheTile(): void
    {
        $ok = Twopayment::API_KEY_STATUS_OK;
        $good = array('http_status' => 200, 'available_terms' => array(30));

        // [key verdict, record response, ever fetched before, tile offered, description].
        $cases = array(
            array($ok, $good, true, true, 'a resolved record offers the tile'),
            array($ok, array('http_status' => 500), false, true, 'a merchant-record 500 does not hide the tile'),
            array($ok, array('http_status' => 0), false, true, 'an unreachable merchant record does not hide the tile'),
            array($ok, array('http_status' => 200, 'available_terms' => array()), false, true, 'an empty offer set does not hide the tile'),
            array($ok, array('http_status' => 200, 'detail' => 'ok'), false, true, 'a 200 that is not the record does not hide the tile'),
            array(Twopayment::API_KEY_STATUS_INVALID, $good, true, false, 'a rejected key hides the tile'),
            array(Twopayment::API_KEY_STATUS_UNREACHABLE, $good, true, true, 'an unreachable key check does not hide the tile'),
            array(Twopayment::API_KEY_STATUS_SERVICE_ERROR, $good, true, true, 'a key check that 5xxd does not hide the tile'),
            array(Twopayment::API_KEY_STATUS_ERROR, $good, true, true, 'a key check that answered some other status does not hide the tile'),
            array(Twopayment::API_KEY_STATUS_NOT_CONFIGURED, $good, true, false, 'an unconfigured key hides the tile'),
        );

        foreach ($cases as list($verdict, $response, $fetched, $offered, $description)) {
            $module = self::harness(array($response));
            if ($fetched) {
                // No minimum and no country allowlist: this test is about the two
                // gates ABN-519 rules on, not the ones the merchant configures.
                Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, '[30]');
                Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, '1');
                Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, time() - 10);
            } else {
                // Nothing ever fetched, so the read below goes to the wire and fails.
                Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, '');
                Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, '');
                Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, 0);
            }
            $terms = $module->getMerchantAvailableTerms();
            TinyAssert::same($fetched ? 0 : 1, $module->calls, 'wire calls: ' . $description);
            if (!$fetched) {
                TinyAssert::same(array(), $terms, 'the record must be unresolved: ' . $description);
            }

            $module->primeTwoApiKeyStatus($verdict, 200);
            self::offerableCart($module);

            TinyAssert::same(
                $offered,
                count($module->hookPaymentOptions(array())) > 0,
                'tile offered: ' . $description
            );
            TinyAssert::same(
                $fetched ? 0 : 1,
                $module->calls,
                'the tile decision must not read the record at all: ' . $description
            );
        }
    }

    /**
     * The route is public, so its guard is the only thing standing between a
     * caller and an outbound fetch. A rejection must not distinguish a wrong
     * token from a route that does not exist.
     */
    private static function testTheScheduledRouteRefusesEveryTokenButTheStoredOne(): void
    {
        $module = self::harness(array(array('http_status' => 200, 'available_terms' => array(30))));
        $stored = $module->getTwoCronToken();

        // [presented token, accepted, description].
        $cases = array(
            array($stored, true, 'the stored token is accepted'),
            array('', false, 'no token is refused'),
            array('   ', false, 'whitespace is refused'),
            array(strtoupper($stored), false, 'a case-changed token is refused'),
            array(substr($stored, 0, -1), false, 'a truncated token is refused'),
            array($stored . 'x', false, 'an extended token is refused'),
        );

        foreach ($cases as list($presented, $accepted, $description)) {
            TinyAssert::same($accepted, $module->isTwoCronTokenValid($presented), 'guard: ' . $description);
        }

        TinyAssert::same(40, strlen($stored), 'the token must be long enough not to be guessed');
        TinyAssert::same($stored, $module->getTwoCronToken(), 'a minted token must be stable across reads');

        $controller = self::cronController($module);
        TinyAssert::same(
            array(404, array('success' => false, 'error' => 'not_found')),
            self::cronResponse($controller, array()),
            'a rejected request must answer 404 and say nothing about why'
        );

        foreach (PrestaShopLogger::$logs as $entry) {
            TinyAssert::true(
                strpos($entry['message'], $stored) === false,
                'the token must never reach the shop log'
            );
        }

        // The URL is public, so an unthrottled line per rejection is a log flood.
        Configuration::updateValue(Twopayment::CONFIG_CRON_REJECT_LOG_TS, 0);
        TinyAssert::true($module->logTwoCronRejection(), 'the first rejection is logged');
        TinyAssert::false($module->logTwoCronRejection(), 'a rejection moments later is not');
        Configuration::updateValue(
            Twopayment::CONFIG_CRON_REJECT_LOG_TS,
            time() - Twopayment::CRON_REJECT_LOG_INTERVAL - 1
        );
        TinyAssert::true($module->logTwoCronRejection(), 'a rejection past the interval is logged again');
    }

    /**
     * Given the token, When it is presented faster than the refresh floor, Then
     * the run is refused without reaching the wire.
     */
    private static function testTheScheduledRouteThrottlesAcceptedRuns(): void
    {
        // [seconds since the last accepted run, status, wire calls, description].
        $cases = array(
            array(null, Twopayment::CRON_STATUS_REFRESHED, 1, 'a first run refreshes'),
            array(0, Twopayment::CRON_STATUS_THROTTLED, 0, 'an immediate second run is throttled'),
            array(Twopayment::CRON_MIN_REFRESH_INTERVAL + 1, Twopayment::CRON_STATUS_REFRESHED, 1, 'a run past the floor refreshes'),
        );

        foreach ($cases as list($since, $expectedStatus, $expectedCalls, $description)) {
            $module = self::harness(array(array('http_status' => 200, 'available_terms' => array(30))));
            if ($since !== null) {
                Configuration::updateValue(Twopayment::CONFIG_CRON_LAST_RUN_TS, time() - $since);
            }

            TinyAssert::same($expectedStatus, $module->runTwoScheduledRefresh(), 'status: ' . $description);
            TinyAssert::same($expectedCalls, $module->calls, 'wire calls: ' . $description);
        }

        // A shop with no key yet is not an error to report.
        $module = self::harness();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
        TinyAssert::same(
            Twopayment::CRON_STATUS_UNCONFIGURED,
            $module->runTwoScheduledRefresh(),
            'status: a shop with no key yet'
        );
        TinyAssert::same(0, $module->calls, 'wire calls: a shop with no key yet');
    }

    /** The mark exists to say the schedule is not running, so a run must clear it. */
    private static function testAScheduledRunClearsTheStandInMark(): void
    {
        $module = self::harness(array(array('http_status' => 200, 'available_terms' => array(30))));
        self::seedHeldRecord(Twopayment::MERCHANT_RECORD_STALE_AFTER + 1);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS, time() - 3600);

        $module->runTwoScheduledRefresh();

        TinyAssert::same(
            0,
            (int) Configuration::get(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS),
            'a scheduled run must clear the stand-in mark'
        );
    }

    /**
     * An install left by a version that still evicted keeps a fetched-looking
     * invoice-distribution row beside a zeroed stamp. There is nothing to serve,
     * so the read retries on the short backoff rather than treating it as a
     * schedule that has stopped.
     */
    private static function testARecordWithNoSuccessStampHasNothingToServe(): void
    {
        $module = self::harness(array(array('http_status' => 0)));
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, '0');
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, '');
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, 0);

        $module->getMerchantAvailableTerms();

        TinyAssert::same(1, $module->calls, 'wire calls: a record with no success stamp');
        TinyAssert::same(
            array(Twopayment::API_TIMEOUT_STATE_CHECK),
            $module->timeouts,
            'a read with nothing to serve fetches on the render cap, so it can succeed'
        );
        TinyAssert::same(
            0,
            (int) Configuration::get(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS),
            'nothing to serve is not a schedule that has stopped'
        );
    }

    /**
     * The record and the API key it is fetched with are written at the context's
     * scope, so an all-shops or group context can neither read one shop's record
     * nor write one that shop will not shadow. Reporting or refreshing there
     * would be a control that lies, or one that does nothing.
     */
    private static function testTheDiagnosticsRowsFollowTheContextTheRecordLivesIn(): void
    {
        // [context, context id, actionable, description]
        $cases = array(
            array(Shop::CONTEXT_SHOP, 1, true, 'a single-shop context can report and refresh'),
            array(Shop::CONTEXT_GROUP, 1, false, 'a group context cannot reach a shop row'),
            array(Shop::CONTEXT_ALL, null, false, 'an all-shops context cannot reach a shop row'),
        );

        foreach ($cases as list($context, $id, $actionable, $description)) {
            $module = self::harness(array(array('http_status' => 200, 'available_terms' => array(30))));
            StubStore::$multistore = true;
            StubStore::$shops = array(1 => 1, 2 => 1, 3 => 2);
            Shop::setContext(Shop::CONTEXT_SHOP, 1);
            self::seedHeldRecord(10);
            Shop::setContext($context, $id);

            TinyAssert::same(
                $actionable,
                $module->isTwoSingleShopContextForTest(),
                'context: ' . $description
            );
            $line = $module->merchantRecordStatusLineForTest();
            TinyAssert::same(
                $actionable
                    ? 'Last refreshed'
                    : 'The cached profile belongs to one shop. Switch to a single shop to see it or refresh it.',
                $actionable ? substr($line, 0, 14) : $line,
                'status line: ' . $description
            );

            $rows = $module->diagnosticsRowHtmlForTest();
            TinyAssert::same(
                $actionable,
                strpos($rows['PS_TWO_REFRESH_MERCHANT_RECORD'], 'id="two-refresh-merchant-record"') !== false,
                'refresh button rendered: ' . $description
            );
            TinyAssert::same(
                $actionable,
                strpos($rows['PS_TWO_CRON_URL'], '<input') === 0,
                'refresh URL rendered: ' . $description
            );

            $module->ajaxProcessRefreshMerchantRecord();
            TinyAssert::same(
                $actionable ? true : false,
                (bool) $module->refreshResponse['success'],
                'refresh endpoint outcome: ' . $description
            );
            TinyAssert::same(
                $actionable ? 1 : 0,
                $module->calls,
                'the endpoint must reach no wire outside a single shop: ' . $description
            );
        }

        StubStore::reset();
    }

    /**
     * The record survives a key change whose refetch cannot succeed, so a merchant who
     * saved a typo during an outage gets everything back by pasting the right key again.
     * A foreign record is withheld, never dropped (ABN-519).
     */
    private static function testAKeyChangeDuringAnOutageIsRecoverableByRevertingTheKey(): void
    {
        $module = self::harness();
        $module->onRequest = static function () {
            return array('http_status' => 0);
        };
        self::seedHeldRecord(10);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_RECORD_KEY, TwopaymentTestHarness::recordKeyStampForTest('key-a'));
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-a');
        $expected = self::heldValues();

        // A typo saved while Two is unreachable.
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-typo');
        TinyAssert::false($module->refreshMerchantRecord(), 'the refetch under the wrong key fails');
        TinyAssert::same(array(), $module->getMerchantAvailableTerms(), 'the foreign record is withheld');
        TinyAssert::same($expected, self::heldValues(), 'but every cached value is still held');

        // The right key pasted back, still no API.
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-a');

        TinyAssert::same(
            array(30, 60),
            $module->getMerchantAvailableTerms(),
            'reverting the key restores what the shop was serving'
        );

        // The line the merchant reads while that is the situation.
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-typo');
        TinyAssert::same(
            'The cached profile was fetched for a different API key, so it is not in use.'
                . ' It is kept until a refresh under this key succeeds.',
            $module->merchantRecordStatusLineForTest(),
            'the Diagnostics line names the foreign-record case'
        );
    }

    /**
     * A second key change inside the backoff must still refetch: the floor belongs to
     * the change that wrote it, and a successful refresh clears it.
     */
    private static function testASuccessfulRefreshClearsTheForeignFloor(): void
    {
        $module = self::harness(array(
            array('http_status' => 200, 'available_terms' => array(30)),
            array('http_status' => 200, 'available_terms' => array(60)),
        ));
        self::seedHeldRecord(10);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_RECORD_KEY, TwopaymentTestHarness::recordKeyStampForTest('key-a'));

        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-b');
        TinyAssert::same(array(30), $module->getMerchantAvailableTerms(), 'the first key change refetches');

        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-c');

        TinyAssert::same(array(60), $module->getMerchantAvailableTerms(), 'so does a second one moments later');
        TinyAssert::same(2, $module->calls, 'each key change gets its own refetch');
    }

    /**
     * A record fetched for another key is not this shop's (ABN-530), so a read holds
     * nothing: it fetches on the ordinary render cap and records no stand-in, rather
     * than treating a foreign stamp as a schedule that has stopped.
     */
    private static function testARecordStampedForAnotherKeyIsNothingToServe(): void
    {
        $module = self::harness(array(array('http_status' => 0)));
        self::seedHeldRecord(Twopayment::MERCHANT_RECORD_STALE_AFTER + 1);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_RECORD_KEY, 'another-key');

        TinyAssert::false($module->hasFetchedMerchantRecord(), 'a foreign record is not one this shop has fetched');

        $module->getMerchantAvailableTerms();

        TinyAssert::same(1, $module->calls, 'wire calls: a record stamped for another key');
        TinyAssert::same(
            array(Twopayment::API_TIMEOUT_STATE_CHECK),
            $module->timeouts,
            'a read with nothing to serve fetches on the render cap, not the stand-in cap'
        );
        TinyAssert::same(
            0,
            (int) Configuration::get(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS),
            'a foreign stamp is not a schedule that has stopped'
        );
    }

    /**
     * A crontab that calls daily and whose fetch keeps failing is a different
     * fault from one that has stopped calling, and must not be reported as it.
     */
    private static function testAFailingScheduleIsNotReportedAsAScheduleThatNeverRan(): void
    {
        // [seconds since the last accepted run, mark expected, description].
        $cases = array(
            array(null, true, 'a schedule that has never run is reported'),
            array(60, false, 'a schedule that ran a minute ago is not'),
            array(Twopayment::MERCHANT_RECORD_STALE_AFTER + 1, true, 'a schedule silent longer than the window is'),
        );

        foreach ($cases as list($since, $marked, $description)) {
            $module = self::harness(array(array('http_status' => 0)));
            self::seedHeldRecord(Twopayment::MERCHANT_RECORD_STALE_AFTER + 1);
            if ($since !== null) {
                Configuration::updateValue(Twopayment::CONFIG_CRON_LAST_RUN_TS, time() - $since);
            }

            $module->getMerchantAvailableTerms();

            TinyAssert::same(1, $module->calls, 'the stand-in runs either way: ' . $description);
            TinyAssert::same(
                $marked,
                (int) Configuration::get(Twopayment::CONFIG_MERCHANT_RECORD_STOOD_IN_TS) > 0,
                'mark: ' . $description
            );
        }
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private static function cronResponse(object $controller, array $request): array
    {
        Tools::resetTestValues();
        foreach ($request as $key => $value) {
            Tools::setTestValue($key, $value);
        }
        try {
            $controller->postProcess();
        } catch (StubCronResponded $e) {
            // respond() ends the request in production; here it just stops.
        }

        return array($controller->status, $controller->payload);
    }

    private static function cronController(object $module): object
    {
        require_once dirname(__DIR__) . '/controllers/front/cron.php';

        $controller = new class extends TwopaymentCronModuleFrontController {
            public int $status = 0;
            /** @var array<string,mixed> */
            public array $payload = array();

            protected function respond($status, array $payload)
            {
                $this->status = (int) $status;
                $this->payload = $payload;
                throw new StubCronResponded();
            }
        };
        $controller->module = $module;

        return $controller;
    }

    private static function offerableCart(object $module): void
    {
        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[904] = array(
            'id_country' => 826,
            'company' => 'Example UK Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        );
        StubStore::$currencies[826] = array('iso_code' => 'GBP', 'loaded' => true);
        StubStore::$moduleCurrencies['twopayment'] = array(array('id_currency' => 826));

        $cart = new Cart(7326);
        $cart->id_address_invoice = 904;
        $cart->id_currency = 826;
        $module->context->cart = $cart;
    }
}
