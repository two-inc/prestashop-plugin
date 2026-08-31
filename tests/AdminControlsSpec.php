<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

/**
 * TWO-25386 - admin controls ported from woocommerce-plugin/magento-plugin
 * into PrestaShop, plus the new order-intent admin toggle.
 *
 * Covers the behavioural wiring, not just the presence of a Configuration
 * key:
 *  - custom payment term days union into getAvailablePaymentTerms();
 *  - the admin-chosen default term wins over the derived default in
 *    getDefaultPaymentTerm() - the highest-priority item in this batch,
 *    since buildTwoBuyerFeeShare()'s surcharge differential-basis calc reads
 *    through the SAME function;
 *  - the order-intent toggle actually gates the front controller's
 *    pre-approval preview call, and leaves the module property correct on
 *    every Configuration state (unset/1/0);
 *  - "clear settings on deactivation" actually gates whether uninstall()
 *    wipes stored Configuration, default-on so existing installs keep their
 *    pre-existing always-clear behaviour;
 *  - the vendor/site name is attached as a request header only when set and
 *    only alongside the API key;
 *  - checkout sort order validation accepts empty/integers and rejects
 *    anything else.
 */
final class AdminControlsSpec
{
    public static function runAll(): void
    {
        self::testCustomDaysUnionedIntoAvailableTerms();
        self::testCustomDaysRejectedWhenNotBackendPermitted();
        self::testCustomDaysIgnoredWhenBlankOrInvalid();
        self::testCustomDaysBypassesEomIntersection();

        self::testAdminDefaultTermWinsOverApiDefault();
        self::testAdminDefaultTermIgnoredWhenNoLongerOffered();
        self::testAdminDefaultTermIrrelevantWithSingleOfferedTerm();
        self::testAbsentAdminDefaultFallsBackToHistoricalDerivation();

        self::testOrderIntentPropertyDefaultsOnWhenUnset();
        self::testOrderIntentPropertyOffWhenConfiguredZero();
        self::testOrderIntentPropertyOnWhenConfiguredOne();
        self::testCheckOrderIntentControllerSkipsCallWhenDisabled();
        self::testCheckOrderIntentControllerProceedsWhenEnabled();

        self::testSkipConfirmTokenCheckBypassesTokenValidation();
        self::testConfirmTokenCheckEnforcedByDefault();

        self::testClearSettingsOnDeactivationDefaultsToTrue();
        self::testClearSettingsOnDeactivationHonoursExplicitOff();
        self::testUninstallSkipsSettingsWipeWhenToggleOff();
        self::testUninstallWipesSettingsWhenToggleOnByDefault();

        self::testVendorNameHeaderIncludedWhenConfigured();
        self::testVendorNameHeaderOmittedWhenBlank();
        self::testVendorNameHeaderOmittedFromUnauthenticatedRequest();

        self::testCheckoutSortOrderValidationAcceptsEmptyAndIntegers();
        self::testCheckoutSortOrderValidationRejectsNonInteger();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    private static function enableTerms(array $days): void
    {
        foreach (Twopayment::PAYMENT_TERMS_OPTIONS as $term) {
            Configuration::updateValue('PS_TWO_PAYMENT_TERMS_' . $term, in_array($term, $days, true) ? 1 : 0);
        }
    }

    // ---- #9 custom payment term days --------------------------------------

    /**
     * 20 is in the hardcoded PAYMENT_TERMS_OPTIONS fallback (the source
     * getOfferableTermSource() returns on a cold backend-terms cache) but is
     * not in EOM_PAYMENT_TERMS_OPTIONS - useful as a "custom" value that is
     * still backend-permitted without needing a live merchant-record fetch.
     */
    private const BACKEND_PERMITTED_CUSTOM_DAYS = 20;

    private static function testCustomDaysUnionedIntoAvailableTerms(): void
    {
        self::reset();
        self::enableTerms([15, 30]);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS', self::BACKEND_PERMITTED_CUSTOM_DAYS);
        $module = new TwopaymentTestHarness();

        TinyAssert::same(array(15, 20, 30), $module->getAvailablePaymentTerms());
    }

    /**
     * A custom day count Two's backend does not offer at all - review-round-1
     * finding: this must NOT be unioned in regardless of what the merchant
     * types, or the custom field becomes a bypass for the backend
     * available_terms restriction (TWO-24813) - a real business/credit-risk
     * control, not just a UI narrowing. 99 is not in PAYMENT_TERMS_OPTIONS
     * (the fallback source on a cold cache) at all.
     */
    private static function testCustomDaysRejectedWhenNotBackendPermitted(): void
    {
        self::reset();
        self::enableTerms([15, 30]);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS', 99);
        $module = new TwopaymentTestHarness();

        TinyAssert::same(array(15, 30), $module->getAvailablePaymentTerms());
    }

    private static function testCustomDaysIgnoredWhenBlankOrInvalid(): void
    {
        self::reset();
        self::enableTerms([15, 30]);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS', '');
        $module = new TwopaymentTestHarness();
        TinyAssert::same(array(15, 30), $module->getAvailablePaymentTerms());

        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS', '0');
        TinyAssert::same(array(15, 30), $module->getAvailablePaymentTerms());

        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS', 'abc');
        TinyAssert::same(array(15, 30), $module->getAvailablePaymentTerms());
    }

    private static function testCustomDaysBypassesEomIntersection(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'EOM');
        self::enableTerms([30]);
        // 20 is backend-permitted (PAYMENT_TERMS_OPTIONS fallback) but not in
        // EOM_PAYMENT_TERMS_OPTIONS - the custom term bypasses the
        // EOM/STANDARD split specifically, not the backend restriction.
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS', self::BACKEND_PERMITTED_CUSTOM_DAYS);
        $module = new TwopaymentTestHarness();

        TinyAssert::same(array(20, 30), $module->getAvailablePaymentTerms());
    }

    // ---- #10 default pre-selected term ------------------------------------

    private static function moduleWithApiDefault(?int $apiDefault): TwopaymentTestHarness
    {
        return new class ($apiDefault) extends TwopaymentTestHarness {
            private $apiDefault;

            public function __construct($apiDefault)
            {
                parent::__construct();
                $this->apiDefault = $apiDefault;
            }

            public function getMerchantDueInDays()
            {
                return $this->apiDefault;
            }
        };
    }

    private static function testAdminDefaultTermWinsOverApiDefault(): void
    {
        self::reset();
        self::enableTerms([7, 15, 30, 60]);
        Configuration::updateValue('PS_TWO_DEFAULT_PAYMENT_TERM', 60);
        // Without the admin override this would resolve to the API default (15).
        $module = self::moduleWithApiDefault(15);

        TinyAssert::same(60, $module->getDefaultPaymentTerm());
    }

    private static function testAdminDefaultTermIgnoredWhenNoLongerOffered(): void
    {
        self::reset();
        self::enableTerms([7, 15, 30]);
        // The merchant later unticked 60 without clearing the old default.
        Configuration::updateValue('PS_TWO_DEFAULT_PAYMENT_TERM', 60);
        $module = self::moduleWithApiDefault(15);

        // Falls through to the next rule in getDefaultPaymentTerm() - the API
        // default, which IS offered.
        TinyAssert::same(15, $module->getDefaultPaymentTerm());
    }

    private static function testAdminDefaultTermIrrelevantWithSingleOfferedTerm(): void
    {
        self::reset();
        self::enableTerms([45]);
        // A conflicting admin default that is not even offered - the single
        // offered term still wins outright, same as before this ticket.
        Configuration::updateValue('PS_TWO_DEFAULT_PAYMENT_TERM', 60);
        $module = self::moduleWithApiDefault(null);

        TinyAssert::same(45, $module->getDefaultPaymentTerm());
    }

    private static function testAbsentAdminDefaultFallsBackToHistoricalDerivation(): void
    {
        self::reset();
        self::enableTerms([7, 15, 30, 60]);
        // No admin override set at all: behaviour must be byte-for-byte the
        // pre-TWO-25386 derivation (API default, else 30, else lowest).
        $module = self::moduleWithApiDefault(null);

        TinyAssert::same(30, $module->getDefaultPaymentTerm());
    }

    // ---- #8 order intent toggle --------------------------------------------

    private static function testOrderIntentPropertyDefaultsOnWhenUnset(): void
    {
        self::reset();
        TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_ORDER_INTENT'));
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->isTwoOrderIntentPreviewEnabled());
    }

    private static function testOrderIntentPropertyOffWhenConfiguredZero(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', 0);
        $module = new TwopaymentTestHarness();

        TinyAssert::false($module->isTwoOrderIntentPreviewEnabled());
    }

    private static function testOrderIntentPropertyOnWhenConfiguredOne(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', 1);
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->isTwoOrderIntentPreviewEnabled());
    }

    /**
     * @return object
     */
    private static function makeOrderIntentController(TwopaymentTestHarness $module)
    {
        $controller = new class extends TwopaymentOrderintentModuleFrontController {
            /** @var array<int,array> */
            public array $emitted = [];

            public function sendJsonResponse($content)
            {
                $decoded = json_decode((string) $content, true);
                $this->emitted[] = is_array($decoded) ? $decoded : array('raw' => $content);

                throw new StubOrderIntentResponded('order intent response sent');
            }
        };
        $controller->module = $module;

        return $controller;
    }

    private static function runCheckOrderIntent($controller): void
    {
        try {
            $controller->ajaxProcessCheckOrderIntent();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }
    }

    private static function testCheckOrderIntentControllerSkipsCallWhenDisabled(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', 0);
        $module = new TwopaymentTestHarness();
        $controller = self::makeOrderIntentController($module);

        self::runCheckOrderIntent($controller);

        TinyAssert::count(1, $controller->emitted);
        TinyAssert::false($controller->emitted[0]['success']);
        TinyAssert::same('order_intent_disabled', $controller->emitted[0]['status']);
    }

    private static function testCheckOrderIntentControllerProceedsWhenEnabled(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', 1);
        $module = new TwopaymentTestHarness();
        $controller = self::makeOrderIntentController($module);
        Tools::setTestValue('token', Tools::getToken(false));
        $_SERVER['REQUEST_METHOD'] = 'POST';

        self::runCheckOrderIntent($controller);

        // Proceeds PAST the toggle gate: whatever it fails on next (no cart,
        // in this stub environment) is a DIFFERENT failure than the disabled
        // one - it has no 'status' key at all - proving the toggle itself
        // let the call through instead of short-circuiting it.
        TinyAssert::count(1, $controller->emitted);
        TinyAssert::false(array_key_exists('status', $controller->emitted[0]) && $controller->emitted[0]['status'] === 'order_intent_disabled');
    }

    // ---- #4 skip confirm-order token check ---------------------------------

    private static function testSkipConfirmTokenCheckBypassesTokenValidation(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SKIP_CONFIRM_TOKEN_CHECK', 1);
        $module = new TwopaymentTestHarness();
        $controller = self::makeOrderIntentController($module);
        // Deliberately NO token set - would fail validation otherwise.

        TinyAssert::true($controller->validateAjaxToken());
    }

    private static function testConfirmTokenCheckEnforcedByDefault(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $controller = self::makeOrderIntentController($module);

        TinyAssert::false($controller->validateAjaxToken());

        Tools::setTestValue('token', Tools::getToken(false));
        TinyAssert::true($controller->validateAjaxToken());
    }

    // ---- #5 clear settings on deactivation --------------------------------

    private static function testClearSettingsOnDeactivationDefaultsToTrue(): void
    {
        self::reset();
        TinyAssert::false(Configuration::hasKey('PS_TWO_CLEAR_SETTINGS_ON_DEACTIVATION'));
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'shouldClearTwoSettingsOnUninstall');
        TinyAssert::true($method->invoke($module));
    }

    private static function testClearSettingsOnDeactivationHonoursExplicitOff(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_CLEAR_SETTINGS_ON_DEACTIVATION', 0);
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'shouldClearTwoSettingsOnUninstall');
        TinyAssert::false($method->invoke($module));
    }

    /**
     * Harness that counts uninstallTwoSettings() calls without touching real
     * hooks/tables, so the toggle's gating of uninstall() can be asserted in
     * isolation.
     */
    private static function moduleCountingSettingsWipe(): object
    {
        return new class () extends TwopaymentTestHarness {
            public int $wipeCount = 0;

            protected function uninstallTwoSettings()
            {
                $this->wipeCount++;

                return true;
            }

            public function unregisterHook($hook_name, $shop_id = null)
            {
                return true;
            }

            protected function uninstallTwoInvoiceAdminTab()
            {
                return true;
            }

            protected function uninstallTwoErrorLogAdminTab()
            {
                return true;
            }

            protected function deleteTwoTables()
            {
                return true;
            }
        };
    }

    private static function testUninstallSkipsSettingsWipeWhenToggleOff(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_CLEAR_SETTINGS_ON_DEACTIVATION', 0);
        $module = self::moduleCountingSettingsWipe();

        $module->uninstall();

        TinyAssert::same(0, $module->wipeCount);
    }

    private static function testUninstallWipesSettingsWhenToggleOnByDefault(): void
    {
        self::reset();
        // No explicit row: default-on preserves the pre-existing always-clear
        // behaviour for every install that has not touched this new switch.
        $module = self::moduleCountingSettingsWipe();

        $module->uninstall();

        TinyAssert::same(1, $module->wipeCount);
    }

    // ---- #1 vendor/site name -----------------------------------------------

    private static function headersFor(TwopaymentTestHarness $module, string $endpoint): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'getTwoRequestHeaders');

        return $method->invoke($module, $endpoint);
    }

    private static function hasVendorHeader(array $headers): bool
    {
        foreach ($headers as $header) {
            if (strpos($header, 'X-Vendor-Name:') === 0) {
                return true;
            }
        }

        return false;
    }

    private static function testVendorNameHeaderIncludedWhenConfigured(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
        Configuration::updateValue('PS_TWO_VENDOR_NAME', 'Shop A');
        $module = new TwopaymentTestHarness();

        $headers = self::headersFor($module, '/v1/order');

        TinyAssert::true(self::hasVendorHeader($headers));
    }

    private static function testVendorNameHeaderOmittedWhenBlank(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
        Configuration::updateValue('PS_TWO_VENDOR_NAME', '');
        $module = new TwopaymentTestHarness();

        $headers = self::headersFor($module, '/v1/order');

        TinyAssert::false(self::hasVendorHeader($headers));
    }

    private static function testVendorNameHeaderOmittedFromUnauthenticatedRequest(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
        Configuration::updateValue('PS_TWO_VENDOR_NAME', 'Shop A');
        $module = new TwopaymentTestHarness();

        // The unauthenticated order-intent preview endpoint never attaches
        // the API key, and must never leak the vendor identity either.
        $headers = self::headersFor($module, '/v1/order_intent/preview');

        TinyAssert::false(self::hasVendorHeader($headers));
    }

    // ---- #6 checkout sort order --------------------------------------------

    private static function validationErrors(TwopaymentTestHarness $module): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'validTwoCheckoutSortOrderValue');
        $method->invoke($module);

        $errorsProperty = new ReflectionProperty(Twopayment::class, 'errors');

        return $errorsProperty->getValue($module);
    }

    private static function testCheckoutSortOrderValidationAcceptsEmptyAndIntegers(): void
    {
        self::reset();
        Tools::setTestValue('PS_TWO_CHECKOUT_SORT_ORDER', '');
        TinyAssert::count(0, self::validationErrors(new TwopaymentTestHarness()));

        self::reset();
        Tools::setTestValue('PS_TWO_CHECKOUT_SORT_ORDER', '5');
        TinyAssert::count(0, self::validationErrors(new TwopaymentTestHarness()));

        self::reset();
        Tools::setTestValue('PS_TWO_CHECKOUT_SORT_ORDER', '-3');
        TinyAssert::count(0, self::validationErrors(new TwopaymentTestHarness()));
    }

    private static function testCheckoutSortOrderValidationRejectsNonInteger(): void
    {
        self::reset();
        Tools::setTestValue('PS_TWO_CHECKOUT_SORT_ORDER', 'first');
        TinyAssert::count(1, self::validationErrors(new TwopaymentTestHarness()));

        self::reset();
        Tools::setTestValue('PS_TWO_CHECKOUT_SORT_ORDER', '1.5');
        TinyAssert::count(1, self::validationErrors(new TwopaymentTestHarness()));
    }
}
