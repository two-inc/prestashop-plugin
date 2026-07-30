<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

/**
 * TWO-25288: forgetting a disowned company, on the server.
 *
 * A buyer who says "my company is not on the list" and types their own name must
 * not have the company they just disowned credit-checked and invoiced. The
 * session company is the FIRST thing the resolver consults - ahead of the address
 * - and it returns that company with no comparison against the address, so a
 * session company that outlives its selection is not a stale-value nuisance, it
 * is a wrong order placed silently in a genuine buyer's name.
 *
 * Two independent mechanisms have to hold, and these specs DRIVE them rather than
 * reading the source:
 *
 *  - the clearCompany action, reached through the controller's own action switch,
 *    empties every key saveCompany writes, and refuses an invalid token;
 *  - Twopayment::hookActionCustomerAddressSave() drops the session organisation
 *    number when the address saves a different company name with no organisation
 *    number beside it. This is the backstop that makes the browser's
 *    fire-and-forget clear safe: it holds whether or not that request arrives.
 *
 * Why behavioural and not a source grep: the coverage this file replaces asserted
 * that the controller source CONTAINED `case 'clearCompany':` and each
 * `unset(...)` literal. An early `return` inserted above those unsets left every
 * literal in place and the whole suite stayed green, and inverting the token guard
 * would have passed identically. Nothing executed the action.
 */
final class SessionCompanyClearSpec
{
    private const ADDRESS_ID = 9811;

    /** @var array<int,string> */
    private const COMPANY_COOKIE_KEYS = [
        'two_company_name',
        'two_company_id',
        'two_company_country',
        'two_company_address_id',
    ];

    public static function runAll(): void
    {
        self::testClearCompanyEmptiesEveryCompanyKey();
        self::testClearCompanyIsReachedThroughTheActionSwitch();
        self::testInvalidTokenClearsNothing();
        self::testMissingTokenClearsNothing();
        self::testAddressSaveDropsTheNumberOfADisownedCompany();
        self::testAddressSaveKeepsTheNumberWhenTheFormSuppliesOne();
        self::testAddressSaveKeepsTheNumberWhenTheCompanyIsUnchanged();
        self::testAddressSaveKeepsTheNumberOnAMerelyRetypedName();
    }

    /* ---- the clearCompany action ---- */

    /**
     * Every key the save action writes is gone afterwards. Asserted key by key:
     * clearing the company while leaving the country or address marker behind is
     * the half-record state whose interpretation differs between the two readers
     * of this cookie.
     */
    private static function testClearCompanyEmptiesEveryCompanyKey(): void
    {
        $controller = self::makeController('token');
        $cookie = self::seedSessionCompany();

        self::runAction($controller);

        TinyAssert::count(1, $controller->emitted, 'the clear must answer the caller');
        TinyAssert::true(
            $controller->emitted[0]['success'],
            'a valid clear reports success'
        );

        foreach (self::COMPANY_COOKIE_KEYS as $key) {
            TinyAssert::false(
                isset($cookie->{$key}),
                'clearCompany left ' . $key . ' behind, so the disowned company survives the clear'
            );
        }
    }

    /**
     * Through postProcess() and the controller's own action switch, not by
     * calling the handler directly - otherwise the case could be deleted from the
     * switch, the request would fall through it and do nothing, and a
     * direct-invocation spec would still pass.
     */
    private static function testClearCompanyIsReachedThroughTheActionSwitch(): void
    {
        $controller = self::makeController('token');
        $cookie = self::seedSessionCompany();

        self::runAction($controller, true);

        TinyAssert::count(1, $controller->emitted, 'the action switch must dispatch clearCompany');
        TinyAssert::true($controller->emitted[0]['success']);
        TinyAssert::false(
            isset($cookie->two_company_id),
            'the clear reached through the switch must actually clear'
        );
    }

    /**
     * The guard is load-bearing and its polarity is part of the contract: an
     * unauthenticated caller must not be able to wipe another session's company
     * data. Inverting the condition passes every source-level check.
     */
    private static function testInvalidTokenClearsNothing(): void
    {
        self::assertRejectedTokenClearsNothing('not-the-token');
    }

    private static function testMissingTokenClearsNothing(): void
    {
        self::assertRejectedTokenClearsNothing('');
    }

    private static function assertRejectedTokenClearsNothing(string $token): void
    {
        $controller = self::makeController($token);
        $cookie = self::seedSessionCompany();

        self::runAction($controller);

        TinyAssert::count(1, $controller->emitted, 'a rejected clear still answers the caller');
        TinyAssert::false(
            $controller->emitted[0]['success'],
            'a clear with token "' . $token . '" must be refused'
        );

        foreach (self::COMPANY_COOKIE_KEYS as $key) {
            TinyAssert::true(
                isset($cookie->{$key}),
                'a refused clear must leave ' . $key . ' untouched'
            );
        }
    }

    /* ---- the address-save backstop ---- */

    /**
     * The state a disowned company reaches the server in: a different company
     * name on the address, and no organisation number beside it because the buyer
     * never selected one.
     *
     * The number has to go. The hook overwrites the cookie's company NAME on the
     * line above, so keeping it would pair one company's organisation number with
     * another company's name - and the resolver returns that pair, ahead of the
     * address, as the company to credit-check.
     */
    private static function testAddressSaveDropsTheNumberOfADisownedCompany(): void
    {
        $cookie = self::seedSessionCompany();
        self::runAddressSave('Unregistered Trading Name', '');

        TinyAssert::false(
            isset($cookie->two_company_id),
            'the disowned company\'s organisation number survived the address save'
        );
        TinyAssert::false(
            isset($cookie->two_company_country),
            'a country marker with no organisation number behind it is the half-record state'
        );
        // The name the buyer actually typed is kept - it is theirs, and the
        // resolver needs a company name for the order either way.
        TinyAssert::same('Unregistered Trading Name', (string) $cookie->two_company_name);
        TinyAssert::same((string) self::ADDRESS_ID, (string) $cookie->two_company_address_id);
    }

    /**
     * The happy path must not regress: a real selection posts its organisation
     * number with the address, and that is the authority.
     */
    private static function testAddressSaveKeepsTheNumberWhenTheFormSuppliesOne(): void
    {
        $cookie = self::seedSessionCompany();
        self::runAddressSave('Another Trading Ltd', '87654321');

        TinyAssert::same('87654321', (string) $cookie->two_company_id);
        TinyAssert::same('Another Trading Ltd', (string) $cookie->two_company_name);
    }

    /**
     * An address re-saved with the company unchanged is not a disowning. The
     * hidden organisation-number field is not always in the POST - editing a
     * street and saving is enough to omit it - so keying the drop on the absent
     * number ALONE would throw away a good selection on an ordinary address edit.
     */
    private static function testAddressSaveKeepsTheNumberWhenTheCompanyIsUnchanged(): void
    {
        $cookie = self::seedSessionCompany();
        self::runAddressSave('Example Trading Ltd', '');

        TinyAssert::same('12345678', (string) $cookie->two_company_id);
        TinyAssert::same('GB', (string) $cookie->two_company_country);
    }

    /**
     * Nor is retyping the same company with different capitalisation or spacing.
     * The comparison is conservative rather than an exact mirror of the browser's
     * own name normalisation — on any ambiguity (an NBSP-for-space swap, say) it
     * is willing to call the names different and drop a stale organisation number
     * rather than risk treating two distinct names as the same one.
     */
    private static function testAddressSaveKeepsTheNumberOnAMerelyRetypedName(): void
    {
        $cookie = self::seedSessionCompany();
        self::runAddressSave("  example   TRADING ltd ", '');

        TinyAssert::same(
            '12345678',
            (string) $cookie->two_company_id,
            'a capitalisation tidy-up is not a disowning and must not cost the organisation number'
        );
    }

    /* ---- fixtures ---- */

    /**
     * A session carrying a completed company selection, exactly the four keys
     * ajaxProcessSaveCompany() writes.
     */
    private static function seedSessionCompany(): Cookie
    {
        $cookie = new Cookie();
        $cookie->two_company_name = 'Example Trading Ltd';
        $cookie->two_company_id = '12345678';
        $cookie->two_company_country = 'GB';
        $cookie->two_company_address_id = (string) self::ADDRESS_ID;
        Context::getContext()->cookie = $cookie;

        return $cookie;
    }

    /**
     * Fire the actionCustomerAddressSave hook the way core does when the buyer
     * saves the address form.
     *
     * @param string $company   the company name on the saved address
     * @param string $companyId the hidden organisation-number field in the POST
     */
    private static function runAddressSave(string $company, string $companyId): void
    {
        Tools::resetTestValues();
        if ($companyId !== '') {
            Tools::setTestValue('companyid', $companyId);
        }

        $address = new stdClass();
        $address->company = $company;
        $address->id = self::ADDRESS_ID;

        $module = new TwopaymentTestHarness();
        $module->hookActionCustomerAddressSave(['address' => $address]);
    }

    /**
     * @param bool $throughSwitch drive postProcess() and the action switch rather
     *                            than the handler directly
     */
    private static function runAction($controller, bool $throughSwitch = false): void
    {
        try {
            if ($throughSwitch) {
                $controller->postProcess();
            } else {
                $controller->ajaxProcessClearCompany();
            }
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }
    }

    private static function makeController(string $token)
    {
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        Tools::setTestValue('ajax', 1);
        Tools::setTestValue('action', 'clearCompany');
        if ($token !== '') {
            Tools::setTestValue('token', $token);
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = new class extends TwopaymentOrderintentModuleFrontController {
            /** @var array<int,array> */
            public array $emitted = [];

            public function sendJsonResponse($content)
            {
                $decoded = json_decode((string) $content, true);
                $this->emitted[] = is_array($decoded) ? $decoded : ['raw' => $content];

                throw new StubOrderIntentResponded('order intent response sent');
            }
        };
        $controller->module = new TwopaymentTestHarness();

        return $controller;
    }
}
