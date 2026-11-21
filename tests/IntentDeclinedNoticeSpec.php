<?php

declare(strict_types=1);

/**
 * A brand overlay may reword either order-intent notice or suppress it
 * entirely, so the DECLINED notice carries the same two keys the approved one
 * has had since TWO-25218 - an explicit-boolean switch and a copy override
 * that can never mean off.
 */
final class IntentDeclinedNoticeSpec
{
    public static function runAll(): void
    {
        self::testSwitchNormalisation();
        self::testCopyNormalisation();
        self::testShippedTwoBrandIsEnabledWithDefaultCopy();
        self::testBothKeysReachTheCheckoutJs();
    }

    private static function testSwitchNormalisation(): void
    {
        $cases = array(
            array(true, true, false, 'an explicit true enables the notice'),
            array(false, false, false, 'an explicit false suppresses the notice'),
            array(null, true, false, 'an absent key is the documented default, not an error'),
            array('', true, true, 'an empty string reads as off under truthiness, so it must be reported and stay enabled'),
            array(0, true, true, 'a zero must be reported and stay enabled'),
            array('false', true, true, 'the string "false" must be reported and stay enabled'),
            array(array(), true, true, 'an array must be reported and stay enabled'),
        );

        foreach ($cases as $case) {
            list($configured, $expected, $expectError, $description) = $case;
            $error = null;
            TinyAssert::same(
                $expected,
                Twopayment::normalizeIntentDeclinedNoticeEnabled($configured, $error),
                $description
            );
            if ($expectError) {
                TinyAssert::notSame(null, $error, $description . ' (error expected)');
                TinyAssert::true(
                    strpos((string) $error, 'intent_declined_notice_enabled') !== false,
                    $description . ' (error must name the declined key, not the approved one)'
                );
            } else {
                TinyAssert::same(null, $error, $description . ' (no error expected)');
            }
        }
    }

    private static function testCopyNormalisation(): void
    {
        $cases = array(
            array(null, null, 'an absent override is the platform default copy'),
            array('', null, 'an empty override is inert, never an off switch'),
            array('   ', null, 'a whitespace-only override is inert too'),
            array('No credit for %s today.', 'No credit for %s today.', 'a non-empty override is carried verbatim'),
            array(false, null, 'a non-string override is the platform default copy'),
        );

        foreach ($cases as $case) {
            list($configured, $expected, $description) = $case;
            TinyAssert::same($expected, Twopayment::normalizeIntentDeclinedNotice($configured), $description);
        }
    }

    private static function testShippedTwoBrandIsEnabledWithDefaultCopy(): void
    {
        StubStore::reset();
        $module = new TwopaymentTestHarness();
        PrestaShopLogger::reset();

        TinyAssert::same(true, $module->isIntentDeclinedNoticeEnabled(), 'the shipped Two brand declares the declined notice ON');
        TinyAssert::same(null, $module->getIntentDeclinedNotice(), 'the shipped Two brand carries no copy override');
        TinyAssert::count(0, PrestaShopLogger::$logs, 'the shipped declaration must not be reported as malformed');
    }

    /**
     * The switch is a real PHP bool so the browser gates on
     * `typeof === 'boolean'` rather than on the falsiness of a copy string.
     */
    private static function testBothKeysReachTheCheckoutJs(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        $module = new TwopaymentTestHarness();
        $module->_path = '/modules/twopayment/';
        $controller = new class extends ModuleFrontController {
            public $php_self = 'order';
            public $controller_name = 'order';

            public function registerStylesheet($id, $path, $options = [])
            {
            }

            public function registerJavascript($id, $path, $options = [])
            {
            }

            public function addJquery()
            {
            }

            public function addJqueryUI($component)
            {
            }
        };
        $controller->module = $module;
        $module->context->controller = $controller;
        $module->context->country = new class {
            public $iso_code = 'NO';
        };

        Media::reset();
        try {
            $module->hookActionFrontControllerSetMedia();
            $published = Media::$jsDef['twopayment'];
        } finally {
            Media::reset();
        }

        TinyAssert::same(true, $published['intent_declined_notice_enabled'], 'the switch must reach the checkout JS as a real boolean');
        TinyAssert::true(array_key_exists('intent_declined_notice', $published), 'the copy override must reach the checkout JS even when null');
        TinyAssert::same(null, $published['intent_declined_notice'], 'the shipped brand publishes no copy override');
    }
}
