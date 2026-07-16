<?php

declare(strict_types=1);

/**
 * TWO-25111 - the plugin-side invoice upload is gated SOLELY on the merchant's
 * server-side `invoice_distributed_by_merchant` flag, sourced from the same
 * GET /v1/merchant fetch/cache seam as available_terms (TWO-24813) and
 * due_in_days (TWO-24859). The manual PS_TWO_USE_OWN_INVOICES admin toggle is
 * retired (TWO-25106, Option A): leftover configuration rows from upgraded
 * shops must have ZERO effect on behaviour.
 */
final class InvoiceUploadGateSpec
{
    public static function runAll(): void
    {
        // Flag semantics (null-safe, absent = false, strict boolean).
        self::testFlagTrueEnablesGate();
        self::testFlagFalseDisablesGate();
        self::testFlagAbsentFromResponseDisablesGate();
        self::testAbsentFlagOverwritesPreviouslyCachedTrue();
        self::testNonBooleanTruthyFlagValueDisablesGate();
        self::testUnresolvedCacheDefaultsToFalse();

        // Cache lifecycle shared with the merchant-record seam.
        self::testFailedFetchServesLastKnownFlag();
        self::testInvalidateFailsClosed();

        // Toggle retirement: remnants of PS_TWO_USE_OWN_INVOICES are inert.
        self::testLeftoverToggleEnabledHasNoEffectWhenFlagUnresolved();
        self::testLeftoverToggleDisabledDoesNotSuppressFlag();
        self::testSourceNeverReadsRetiredToggle();
    }

    /** Set the identity config the merchant-record fetch guards on. */
    private static function configureMerchantIdentity(): void
    {
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-123');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
    }

    /**
     * Harness whose GET /v1/merchant fetch (setTwoPaymentRequest) is stubbed,
     * so the flag caching can be asserted offline.
     */
    private static function moduleWithMerchantResponse($response): object
    {
        return new class ($response) extends TwopaymentTestHarness {
            public int $fetchCount = 0;
            private $response;

            public function __construct($response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->fetchCount++;
                return $this->response;
            }
        };
    }

    /** A 200 merchant record; $flag === null omits the field entirely. */
    private static function okResponse($flag): array
    {
        $body = array('http_status' => Twopayment::HTTP_STATUS_OK, 'available_terms' => array(30));
        if ($flag !== null) {
            $body['invoice_distributed_by_merchant'] = $flag;
        }
        return $body;
    }

    private static function refreshedModule($response): object
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        $module = self::moduleWithMerchantResponse($response);
        $module->getMerchantAvailableTerms(true); // sanctioned refresh point
        return $module;
    }

    // ---- Flag semantics ---------------------------------------------------

    private static function testFlagTrueEnablesGate(): void
    {
        $module = self::refreshedModule(self::okResponse(true));

        TinyAssert::same(1, $module->fetchCount);
        TinyAssert::true($module->isMerchantInvoiceDistributed());
    }

    private static function testFlagFalseDisablesGate(): void
    {
        $module = self::refreshedModule(self::okResponse(false));

        TinyAssert::false($module->isMerchantInvoiceDistributed());
    }

    private static function testFlagAbsentFromResponseDisablesGate(): void
    {
        $module = self::refreshedModule(self::okResponse(null));

        TinyAssert::false($module->isMerchantInvoiceDistributed());
    }

    private static function testAbsentFlagOverwritesPreviouslyCachedTrue(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, 1);

        $module = self::moduleWithMerchantResponse(self::okResponse(null));
        $module->getMerchantAvailableTerms(true);

        // Unlike the term list (serve-stale on omission), an absent flag is a
        // definitive "not enabled" and must overwrite the stale entitlement.
        TinyAssert::false($module->isMerchantInvoiceDistributed());
    }

    private static function testNonBooleanTruthyFlagValueDisablesGate(): void
    {
        // Defensive: the API contract is a JSON boolean; anything else must
        // not open the gate ("true"/1 strings fail the strict === true check).
        $module = self::refreshedModule(self::okResponse('true'));

        TinyAssert::false($module->isMerchantInvoiceDistributed());
    }

    private static function testUnresolvedCacheDefaultsToFalse(): void
    {
        StubStore::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false($module->isMerchantInvoiceDistributed());
    }

    // ---- Cache lifecycle --------------------------------------------------

    private static function testFailedFetchServesLastKnownFlag(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, 1);

        $module = self::moduleWithMerchantResponse(array('http_status' => 500));
        $module->getMerchantAvailableTerms(true);

        // A network blip must not flap the gate off mid-entitlement; only a
        // successful response (with the field absent or false) revokes it.
        TinyAssert::same(1, $module->fetchCount);
        TinyAssert::true($module->isMerchantInvoiceDistributed());
    }

    private static function testInvalidateFailsClosed(): void
    {
        StubStore::reset();
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, 1);

        $module = new TwopaymentTestHarness();
        $module->invalidateMerchantAvailableTerms();

        // Identity change: the old merchant's upload entitlement must never
        // carry over to the new identity.
        TinyAssert::false($module->isMerchantInvoiceDistributed());
    }

    // ---- Retired toggle is inert -------------------------------------------

    private static function testLeftoverToggleEnabledHasNoEffectWhenFlagUnresolved(): void
    {
        StubStore::reset();
        // Upgraded shop with a leftover enabled toggle row and no resolved flag.
        Configuration::updateValue('PS_TWO_USE_OWN_INVOICES', 1);

        $module = new TwopaymentTestHarness();

        TinyAssert::false($module->isMerchantInvoiceDistributed());
    }

    private static function testLeftoverToggleDisabledDoesNotSuppressFlag(): void
    {
        // The inverse remnant: a shop that had the toggle explicitly OFF but
        // whose merchant record now carries the flag - the flag wins.
        StubStore::reset();
        self::configureMerchantIdentity();
        Configuration::updateValue('PS_TWO_USE_OWN_INVOICES', 0);

        $module = self::moduleWithMerchantResponse(self::okResponse(true));
        $module->getMerchantAvailableTerms(true);

        TinyAssert::true($module->isMerchantInvoiceDistributed());
    }

    private static function testSourceNeverReadsRetiredToggle(): void
    {
        // Belt-and-braces for "old config remnants must have zero effect"
        // (TWO-25111 validation): the runtime module source must not READ
        // PS_TWO_USE_OWN_INVOICES anywhere. The only sanctioned mentions are
        // deletions (uninstall cleanup in twopayment.php, upgrade-2.6.0.php)
        // and the historical upgrade-2.2.0.php that introduced it.
        $root = dirname(__DIR__);
        $sources = array_merge(
            array($root . '/twopayment.php'),
            glob($root . '/classes/*.php') ?: array(),
            glob($root . '/controllers/*/*.php') ?: array()
        );

        foreach ($sources as $file) {
            $lines = file($file);
            TinyAssert::true(is_array($lines), 'Unreadable source file ' . $file);
            foreach ($lines as $number => $line) {
                if (strpos($line, 'PS_TWO_USE_OWN_INVOICES') === false) {
                    continue;
                }
                $isDeletion = strpos($line, "Configuration::deleteByName('PS_TWO_USE_OWN_INVOICES')") !== false;
                $isComment = preg_match('#^\s*(//|\*|/\*)#', $line) === 1;
                TinyAssert::true(
                    $isDeletion || $isComment,
                    'Retired toggle PS_TWO_USE_OWN_INVOICES referenced (non-deletion) at '
                    . $file . ':' . ($number + 1) . ' - ' . trim($line)
                );
            }
        }
    }
}
