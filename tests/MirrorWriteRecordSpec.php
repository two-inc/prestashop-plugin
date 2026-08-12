<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

/**
 * TWO-40: the cart-scoped record of what the mirror LAST WROTE into the secondary
 * address.
 *
 * The record exists because the secondary address's sync pin is evaluated when the
 * invoice form APPEARS, and on PrestaShop that is a page load. At that moment every
 * `data-two-autofilled-value` marker the previous page wrote has gone with the nodes
 * that carried it and page memory is empty, so "does this field still hold what the
 * mirror put there" has no answer unless the answer survived the navigation.
 *
 * Two properties are what these specs exist to hold, and both are the whole reason
 * this is a SEPARATE record from the company selection rather than four more keys
 * inside it:
 *
 *  - clearing the company selection must NOT clear this. The company record is
 *    destructible by design - the country guards wipe it, the address-save hook
 *    drops half of it - and none of those events makes the buyer stop owning the
 *    street they typed into their billing address;
 *  - a cart id change must clear BOTH, because a record from a previous order
 *    describes fields on an address that order carried away with it.
 *
 * Driven through the real reader/writer/clearer and the real controller action, not
 * by grepping the source: an early return above a write would leave every literal
 * in place.
 */
final class MirrorWriteRecordSpec
{
    private const CART_ID = 5501;

    private const OTHER_CART_ID = 5502;

    private const ADDRESS_ID = 9811;

    /** @var array<int,string> */
    private const MIRROR_COOKIE_KEYS = [
        'two_mirror_company',
        'two_mirror_org',
        'two_mirror_country',
        'two_mirror_address1',
        'two_mirror_postcode',
        'two_mirror_city',
        'two_mirror_cart_id',
    ];

    /** @var array<int,string> */
    private const COMPANY_COOKIE_KEYS = [
        'two_company_name',
        'two_company_id',
        'two_company_country',
        'two_company_address_id',
        'two_company_cart_id',
    ];

    public static function runAll(): void
    {
        self::testCurrentCartRecordIsFullyReadable();
        self::testEveryComparableFieldHasItsOwnKey();
        self::testRecordFromAnotherCartIsInvisibleAndCleared();
        self::testUnstampedRecordIsInvisibleAndCleared();
        self::testNoLoadedCartReadsAbsentWithoutClearing();
        self::testNoCartWritesNothingAndClearsNothing();
        self::testPartialWriteLeavesTheOtherFieldsAlone();
        self::testNullRemovesAFieldWithoutClearingTheRest();
        self::testUnknownWriteFieldIsReported();
        self::testClearingTheCompanySelectionKeepsTheMirrorRecord();
        self::testClearingTheMirrorRecordKeepsTheCompanySelection();
        self::testCartChangeInvalidatesBothRecords();
        self::testSaveActionIsReachedThroughTheActionSwitch();
        self::testSaveActionRecordsAnEmptyStringAsARealValue();
        self::testSaveActionUppercasesTheCountry();
        self::testInvalidTokenRecordsNothing();
        self::testGetRequestRecordsNothing();
        self::testBodyWithNoKnownFieldRecordsNothing();
        self::testPublishedPayloadCarriesTheRecord();
    }

    /* ---- the record itself ---- */

    private static function testCurrentCartRecordIsFullyReadable(): void
    {
        self::seedMirrorRecord();
        $module = self::makeModule(self::CART_ID);

        $record = $module->readTwoCartScopedMirrorWrites();

        TinyAssert::true(is_array($record), 'a record stamped with the current cart must be readable');
        TinyAssert::same('Example Trading Ltd', (string) $record['company']);
        TinyAssert::same('12345678', (string) $record['organization']);
        TinyAssert::same('GB', (string) $record['country']);
        TinyAssert::same('1 Register Street', (string) $record['address1']);
        TinyAssert::same('EC1A 1BB', (string) $record['postcode']);
        TinyAssert::same('London', (string) $record['city']);
    }

    /**
     * Doug's ruling is that ANY address field the buyer has entered pins the
     * address, and the pin's test is a content match against what the mirror last
     * wrote. A field with nowhere to keep its last-written value cannot be judged
     * that way at all, so the set of keys IS the set of fields the rule covers -
     * assert it explicitly rather than leaving it to whichever spec happens to
     * mention each one.
     */
    private static function testEveryComparableFieldHasItsOwnKey(): void
    {
        $fields = array_keys(Twopayment::MIRROR_WRITE_SESSION_KEYS);
        sort($fields);

        // `address2` and `state` joined the set when the sole-trader autofill began
        // routing building/apartment and region into them (TWO-40, Doug's ruling): a
        // buyer typing into a second address line or a county is stating an
        // independent answer exactly as much as one typing a city, so those fields
        // pin the address like any other. Leaving them untracked would have made the
        // pin miss a real case of buyer-entered data, against the address-wide rule.
        TinyAssert::same(
            ['address1', 'address2', 'city', 'company', 'country', 'organization', 'postcode', 'state'],
            $fields,
            'the record must carry a last-written value for every field the pin compares'
        );
        TinyAssert::same(
            8,
            count(array_unique(array_values(Twopayment::MIRROR_WRITE_SESSION_KEYS))),
            'two fields sharing a cookie key would make one of them unreadable'
        );
    }

    private static function testRecordFromAnotherCartIsInvisibleAndCleared(): void
    {
        $cookie = self::seedMirrorRecord(self::OTHER_CART_ID);
        $module = self::makeModule(self::CART_ID);

        TinyAssert::same(
            null,
            $module->readTwoCartScopedMirrorWrites(),
            'a record written on another cart must not be readable on this one'
        );
        foreach (self::MIRROR_COOKIE_KEYS as $key) {
            TinyAssert::false(
                isset($cookie->{$key}),
                'a record belonging to another cart must be cleared, not merely ignored: ' . $key
            );
        }
    }

    private static function testUnstampedRecordIsInvisibleAndCleared(): void
    {
        $cookie = self::seedMirrorRecord(null);
        $module = self::makeModule(self::CART_ID);

        TinyAssert::same(
            null,
            $module->readTwoCartScopedMirrorWrites(),
            'an unstamped record must not be readable'
        );
        TinyAssert::false(isset($cookie->two_mirror_company), 'an unstamped record must be cleared');
        TinyAssert::false(isset($cookie->two_mirror_address1), 'an unstamped record must be cleared');
    }

    /**
     * The address hooks fire outside checkout, where there is no cart to compare
     * against. Absent, but never destroyed - the same policy the company reader
     * follows, and for the same reason.
     */
    private static function testNoLoadedCartReadsAbsentWithoutClearing(): void
    {
        $cookie = self::seedMirrorRecord();
        $module = self::makeModule(0);

        TinyAssert::same(
            null,
            $module->readTwoCartScopedMirrorWrites(),
            'with no loaded cart nothing can be matched, so the record must read absent'
        );
        TinyAssert::true(
            isset($cookie->two_mirror_address1),
            'a record that merely could not be judged must survive intact'
        );
    }

    private static function testNoCartWritesNothingAndClearsNothing(): void
    {
        $cookie = self::seedMirrorRecord();
        $module = self::makeModule(0);

        $module->storeTwoCartScopedMirrorWrites(['company' => 'Somewhere Else Ltd']);

        TinyAssert::same(
            'Example Trading Ltd',
            (string) $cookie->two_mirror_company,
            'with no cart to stamp against, the write must leave the existing record exactly as it was'
        );
        TinyAssert::same(
            (string) self::CART_ID,
            (string) $cookie->two_mirror_cart_id,
            'a stamp of 0 would make the record unreadable and then destroy it on the next read'
        );
    }

    /**
     * The mirror reports one field at a time: a country-only write must not have to
     * republish the company, or a write racing another would erase it.
     */
    private static function testPartialWriteLeavesTheOtherFieldsAlone(): void
    {
        $cookie = self::seedMirrorRecord();
        $module = self::makeModule(self::CART_ID);

        $module->storeTwoCartScopedMirrorWrites(['country' => 'FR']);

        $record = $module->readTwoCartScopedMirrorWrites();
        TinyAssert::same('FR', (string) $record['country']);
        TinyAssert::same('Example Trading Ltd', (string) $record['company']);
        TinyAssert::same('1 Register Street', (string) $record['address1']);
    }

    private static function testNullRemovesAFieldWithoutClearingTheRest(): void
    {
        $cookie = self::seedMirrorRecord();
        $module = self::makeModule(self::CART_ID);

        $module->storeTwoCartScopedMirrorWrites(['organization' => null]);

        TinyAssert::false(
            isset($cookie->two_mirror_org),
            'a field given as null must be removed'
        );
        $record = $module->readTwoCartScopedMirrorWrites();
        TinyAssert::same('', (string) $record['organization'], 'a removed field reads as nothing written');
        TinyAssert::same('Example Trading Ltd', (string) $record['company']);
    }

    private static function testUnknownWriteFieldIsReported(): void
    {
        self::seedMirrorRecord();
        PrestaShopLogger::reset();
        $module = self::makeModule(self::CART_ID);

        $module->storeTwoCartScopedMirrorWrites(['addressOne' => '2 Other Street']);

        $logged = false;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos((string) $entry['message'], 'unknown mirror-write session field') !== false) {
                $logged = true;
            }
        }
        TinyAssert::true(
            $logged,
            'a mistyped field name must be reported, not silently dropped - the buyer loses a field of protection'
        );
    }

    /* ---- the two records have different invalidation rules ---- */

    /**
     * THE requirement. Every path that clears the company selection - a country
     * guard rejecting it, the buyer declaring their company is not on the register -
     * is a statement about the COMPANY. None of them is a statement that the buyer
     * no longer owns the street they typed into their billing address, and treating
     * one as the other is how the pin loses the data it exists to protect.
     */
    private static function testClearingTheCompanySelectionKeepsTheMirrorRecord(): void
    {
        $cookie = self::seedBothRecords();
        $module = self::makeModule(self::CART_ID);

        $module->clearTwoCartScopedCompany();

        foreach (self::COMPANY_COOKIE_KEYS as $key) {
            TinyAssert::false(
                isset($cookie->{$key}),
                'clearing the company selection must still clear every company key: ' . $key
            );
        }
        $record = $module->readTwoCartScopedMirrorWrites();
        TinyAssert::true(
            is_array($record),
            'clearing the company selection must not take the mirror-write record with it'
        );
        TinyAssert::same('1 Register Street', (string) $record['address1']);
        TinyAssert::same('Example Trading Ltd', (string) $record['company']);
    }

    /**
     * And the converse, so the separation cannot be satisfied by one clearer having
     * quietly become the other's.
     */
    private static function testClearingTheMirrorRecordKeepsTheCompanySelection(): void
    {
        $cookie = self::seedBothRecords();
        $module = self::makeModule(self::CART_ID);

        $module->clearTwoCartScopedMirrorWrites();

        foreach (self::MIRROR_COOKIE_KEYS as $key) {
            TinyAssert::false(
                isset($cookie->{$key}),
                'clearing the mirror-write record must clear every one of its keys: ' . $key
            );
        }
        TinyAssert::true(
            is_array($module->readTwoCartScopedCompany()),
            'clearing the mirror-write record must leave the company selection alone'
        );
    }

    /**
     * The one event that DOES invalidate both: the cart the record describes is
     * gone, so the address it describes went with it.
     */
    private static function testCartChangeInvalidatesBothRecords(): void
    {
        self::seedBothRecords(self::OTHER_CART_ID);
        $module = self::makeModule(self::CART_ID);

        TinyAssert::same(
            null,
            $module->readTwoCartScopedMirrorWrites(),
            'a mirror-write record from another cart must read absent'
        );
        TinyAssert::same(
            null,
            $module->readTwoCartScopedCompany(),
            'a company selection from another cart must read absent'
        );
    }

    /* ---- the browser's report of what it wrote ---- */

    private static function testSaveActionIsReachedThroughTheActionSwitch(): void
    {
        $controller = self::makeController('token', 'saveMirrorWrites');
        $cookie = self::attachCookie(null);
        Tools::setTestValue('company', 'Acme Trading Ltd');
        Tools::setTestValue('organization', '87654321');
        Tools::setTestValue('country', 'GB');

        try {
            // displayAjax(), not the handler directly: an action missing from the
            // switch is unreachable however well the handler works.
            $controller->displayAjax();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

        TinyAssert::same(true, $controller->emitted[0]['success'] ?? null);
        TinyAssert::same('Acme Trading Ltd', (string) $cookie->two_mirror_company);
        TinyAssert::same('87654321', (string) $cookie->two_mirror_org);
        TinyAssert::same('GB', (string) $cookie->two_mirror_country);
        TinyAssert::same(
            (string) self::CART_ID,
            (string) $cookie->two_mirror_cart_id,
            'the action must stamp the cart it wrote under'
        );
    }

    /**
     * An empty string is a real report and not an omission: it says nothing of the
     * plugin's is in that field any more. Recording it as "no change" would leave a
     * value on record that the form no longer holds, and the next page load would
     * then read the field as pinned on the strength of a write that was undone.
     */
    private static function testSaveActionRecordsAnEmptyStringAsARealValue(): void
    {
        $controller = self::makeController('token', 'saveMirrorWrites');
        $cookie = self::attachCookie(self::CART_ID);
        $cookie->two_mirror_city = 'London';
        Tools::setTestValue('city', '');

        try {
            $controller->ajaxProcessSaveMirrorWrites();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

        TinyAssert::same(
            '',
            (string) $cookie->two_mirror_city,
            'an empty report must overwrite the recorded value, not be treated as an omission'
        );
    }

    private static function testSaveActionUppercasesTheCountry(): void
    {
        $controller = self::makeController('token', 'saveMirrorWrites');
        $cookie = self::attachCookie(null);
        Tools::setTestValue('country', 'gb');

        try {
            $controller->ajaxProcessSaveMirrorWrites();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

        TinyAssert::same(
            'GB',
            (string) $cookie->two_mirror_country,
            'the country is compared as an ISO code, so it is stored in one case'
        );
    }

    private static function testInvalidTokenRecordsNothing(): void
    {
        $controller = self::makeController('wrong-token', 'saveMirrorWrites');
        $cookie = self::attachCookie(null);
        Tools::setTestValue('company', 'Acme Trading Ltd');

        try {
            $controller->displayAjax();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

        TinyAssert::same(false, $controller->emitted[0]['success'] ?? null);
        TinyAssert::false(
            isset($cookie->two_mirror_company),
            'an unauthenticated request must not write into the record'
        );
    }

    private static function testGetRequestRecordsNothing(): void
    {
        $controller = self::makeController('token', 'saveMirrorWrites');
        $cookie = self::attachCookie(null);
        Tools::setTestValue('company', 'Acme Trading Ltd');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $controller->displayAjax();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';

        TinyAssert::same(false, $controller->emitted[0]['success'] ?? null);
        TinyAssert::false(
            isset($cookie->two_mirror_company),
            'a state-changing action must refuse a GET'
        );
    }

    private static function testBodyWithNoKnownFieldRecordsNothing(): void
    {
        $controller = self::makeController('token', 'saveMirrorWrites');
        $cookie = self::attachCookie(null);

        try {
            $controller->displayAjax();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

        TinyAssert::same(false, $controller->emitted[0]['success'] ?? null);
        TinyAssert::false(
            isset($cookie->two_mirror_cart_id),
            'a body carrying nothing to record must not stamp an empty record into existence'
        );
    }

    /**
     * The browser cannot evaluate the pin without being told what was written, and
     * the value has to reach it on the page load that renders the form - so it goes
     * in the same payload the confirmed company selection does.
     */
    private static function testPublishedPayloadCarriesTheRecord(): void
    {
        self::seedMirrorRecord();
        $module = self::makeModule(self::CART_ID);

        $published = $module->readTwoCartScopedMirrorWrites();
        TinyAssert::true(is_array($published));

        $source = (string) file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::true(
            strpos($source, "'mirror_writes' => \$this->readTwoCartScopedMirrorWrites()") !== false,
            'the record must be published to the checkout JS, or the pin has nothing to compare against'
        );
    }

    /* ---- fixtures ---- */

    /**
     * A cookie carrying a full mirror-write record, stamped with the given cart
     * (null omits the stamp entirely - what a cookie written before this record
     * existed looks like).
     */
    private static function seedMirrorRecord($cartId = self::CART_ID): Cookie
    {
        $cookie = new Cookie();
        $cookie->two_mirror_company = 'Example Trading Ltd';
        $cookie->two_mirror_org = '12345678';
        $cookie->two_mirror_country = 'GB';
        $cookie->two_mirror_address1 = '1 Register Street';
        $cookie->two_mirror_postcode = 'EC1A 1BB';
        $cookie->two_mirror_city = 'London';
        if ($cartId !== null) {
            $cookie->two_mirror_cart_id = (string) $cartId;
        }
        Context::getContext()->cookie = $cookie;
        self::attachCart(self::CART_ID);

        return $cookie;
    }

    private static function seedBothRecords(int $cartId = self::CART_ID): Cookie
    {
        $cookie = self::seedMirrorRecord($cartId);
        $cookie->two_company_name = 'Example Trading Ltd';
        $cookie->two_company_id = '12345678';
        $cookie->two_company_country = 'GB';
        $cookie->two_company_address_id = (string) self::ADDRESS_ID;
        $cookie->two_company_cart_id = (string) $cartId;

        return $cookie;
    }

    private static function attachCookie($cartId): Cookie
    {
        $cookie = new Cookie();
        if ($cartId !== null) {
            $cookie->two_mirror_cart_id = (string) $cartId;
        }
        Context::getContext()->cookie = $cookie;

        return $cookie;
    }

    private static function attachCart(int $cartId): void
    {
        if ($cartId <= 0) {
            Context::getContext()->cart = null;

            return;
        }

        StubStore::$carts[$cartId] = ['id_address_invoice' => self::ADDRESS_ID];
        Context::getContext()->cart = new Cart($cartId);
    }

    private static function makeModule(int $cartId): TwopaymentTestHarness
    {
        self::attachCart($cartId);

        return new TwopaymentTestHarness();
    }

    private static function makeController(string $token, string $action)
    {
        PrestaShopLogger::reset();
        StubStore::reset();
        Tools::resetTestValues();
        Tools::setTestValue('ajax', 1);
        Tools::setTestValue('action', $action);
        if ($token !== '') {
            Tools::setTestValue('token', $token);
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::attachCart(self::CART_ID);

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
