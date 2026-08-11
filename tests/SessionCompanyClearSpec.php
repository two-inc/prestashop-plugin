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

    private const CART_ID = 4471;

    private const OTHER_CART_ID = 4472;

    private const CUSTOMER_ID = 9812;

    private const CURRENCY_ID = 826;

    private const COUNTRY_ID = 17;

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
        self::testClearCompanyEmptiesEveryCompanyKey();
        self::testClearCompanyIsReachedThroughTheActionSwitch();
        self::testInvalidTokenClearsNothing();
        self::testMissingTokenClearsNothing();
        self::testAddressSaveDropsTheNumberOfADisownedCompany();
        self::testAddressSaveKeepsTheNumberWhenTheFormSuppliesOne();
        self::testAddressSaveKeepsTheNumberWhenTheCompanyIsUnchanged();
        self::testAddressSaveKeepsTheNumberOnAMerelyRetypedName();
        self::testAddressSaveKeepsCountryMarkerWhenNumberWasAlreadyEmpty();
        self::testCurrentCartRecordIsFullyReadable();
        self::testRecordFromAnotherCartIsInvisible();
        self::testRecordFromAnotherCartIsCleared();
        self::testUnstampedLegacyRecordIsInvisibleAndCleared();
        self::testEveryWriteStampsTheCurrentCart();
        self::testNoLoadedCartReadsAbsentWithoutClearing();
        self::testNoCartWritesNothingAndClearsNothing();
        self::testUnknownWriteFieldIsReported();
        self::testCountryMismatchStillWipesTheRecord();
        self::testLegacyRecordWithoutCountryMarkerStillWipesTheRecord();
        self::testMatchingCountryStillReturnsTheRecord();
    }

    /* ---- TWO-40: the record is scoped to the cart it was chosen in ---- */

    /**
     * The happy path first, so the rest cannot pass by breaking persistence
     * outright: a record stamped with the cart the request is running against is
     * returned in full.
     */
    private static function testCurrentCartRecordIsFullyReadable(): void
    {
        self::seedSessionCompany();
        $module = self::makeModule(self::CART_ID);

        $record = $module->readTwoCartScopedCompany();

        TinyAssert::true(is_array($record), 'a record stamped with the current cart must be readable');
        TinyAssert::same('Example Trading Ltd', (string) $record['name']);
        TinyAssert::same('12345678', (string) $record['id']);
        TinyAssert::same('GB', (string) $record['country']);
        TinyAssert::same((string) self::ADDRESS_ID, (string) $record['address_id']);
    }

    /**
     * The requirement itself. A cart id changes at order placement, so a record
     * stamped with cart A must be invisible once the buyer is on cart B - which is
     * what stops a company selected for one order being credit-checked on a later
     * one.
     */
    private static function testRecordFromAnotherCartIsInvisible(): void
    {
        self::seedSessionCompany(self::OTHER_CART_ID);
        $module = self::makeModule(self::CART_ID);

        TinyAssert::same(
            null,
            $module->readTwoCartScopedCompany(),
            'a company chosen in another cart must not be readable in this one'
        );

        // And it must not reach the resolver either - the reader is the only gate,
        // so a caller that still saw it through the validated path would defeat it.
        $validated = $module->getTwoValidatedSessionCompanyData('GB');
        TinyAssert::same('', (string) $validated['company_name']);
        TinyAssert::same('', (string) $validated['organization_number']);
    }

    private static function testRecordFromAnotherCartIsCleared(): void
    {
        $cookie = self::seedSessionCompany(self::OTHER_CART_ID);
        $module = self::makeModule(self::CART_ID);

        $module->readTwoCartScopedCompany();

        foreach (self::COMPANY_COOKIE_KEYS as $key) {
            TinyAssert::false(
                isset($cookie->{$key}),
                'a record belonging to another cart must be cleared, not merely ignored: ' . $key
            );
        }
    }

    /**
     * A cookie written by a version before TWO-40 carries no cart stamp at all.
     * That reads as absent and is cleared. There is deliberately no migration: the
     * selection is only needed up to order placement, so the whole cost of
     * discarding one is that the buyer re-picks their company.
     */
    private static function testUnstampedLegacyRecordIsInvisibleAndCleared(): void
    {
        $cookie = self::seedSessionCompany(null);
        $module = self::makeModule(self::CART_ID);

        TinyAssert::same(
            null,
            $module->readTwoCartScopedCompany(),
            'an unstamped legacy record must not be readable'
        );
        TinyAssert::false(
            isset($cookie->two_company_id),
            'an unstamped legacy record must be cleared'
        );
        TinyAssert::false(
            isset($cookie->two_company_name),
            'an unstamped legacy record must be cleared'
        );
    }

    /**
     * Drift is the failure mode centralising the keys exists to prevent, so assert
     * the stamp lands on EVERY write path rather than only on the helper. All
     * three of them: the address-save hook, the save action the browser calls,
     * and the order-intent handler's own store-back
     * (TwopaymentOrderintentModuleFrontController::storeCompanyDataInSession()).
     *
     * The third is driven through ajaxProcessCheckOrderIntent() - the public
     * entry point that calls it - rather than by reflection, so a write site
     * deleted from that handler fails here too.
     */
    private static function testEveryWriteStampsTheCurrentCart(): void
    {
        $cookie = self::seedSessionCompany(null);
        self::runAddressSave('Another Trading Ltd', '87654321', self::CART_ID);

        TinyAssert::same(
            (string) self::CART_ID,
            (string) $cookie->two_company_cart_id,
            'the address-save hook must stamp the cart it wrote under'
        );

        $controller = self::makeController('token', 'saveCompany');
        $cookie = self::seedSessionCompany(null);
        Tools::setTestValue('company', 'Saved Trading Ltd');
        Tools::setTestValue('companyid', '55555555');
        Tools::setTestValue('country', 'GB');
        Tools::setTestValue('id_address', (string) self::ADDRESS_ID);
        try {
            $controller->ajaxProcessSaveCompany();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

        TinyAssert::same(
            (string) self::CART_ID,
            (string) $cookie->two_company_cart_id,
            'the save action must stamp the cart it wrote under'
        );
        TinyAssert::same('55555555', (string) $cookie->two_company_id);

        // Third write site: the order-intent handler stores the company identity
        // it resolved back into the session on its way to building the payload.
        $controller = self::makeOrderIntentController();
        $cookie = Context::getContext()->cookie;
        try {
            $controller->ajaxProcessCheckOrderIntent();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

        TinyAssert::same(
            'Intent Trading Ltd',
            (string) $cookie->two_company_name,
            'the order-intent handler must store the company it resolved - if it did not, ' .
            'this spec is not reaching the write site it exists to cover'
        );
        TinyAssert::same(
            (string) self::CART_ID,
            (string) $cookie->two_company_cart_id,
            'the order-intent store-back must stamp the cart it wrote under'
        );
    }

    /**
     * A hook can fire outside checkout, where there is no cart to compare
     * against. That reads as absent - it must never match - but it must NOT clear:
     * wiping a record the buyer is still mid-way through using would be worse than
     * declining to read it here.
     */
    private static function testNoLoadedCartReadsAbsentWithoutClearing(): void
    {
        $cookie = self::seedSessionCompany();
        $module = self::makeModule(0);

        TinyAssert::same(
            null,
            $module->readTwoCartScopedCompany(),
            'with no loaded cart nothing can be matched, so the record must read absent'
        );
        TinyAssert::true(
            isset($cookie->two_company_id),
            'a request with no cart must not destroy a record it cannot judge'
        );

        // The commoner production shape, and the one the fixture above does NOT
        // exercise: the context carries a Cart object, it just has no id yet
        // because nothing has been saved to it. `$context->cart` is set and is an
        // object, so only the id can be what makes this a no-cart request.
        $cookie = self::seedSessionCompany();
        $module = self::makeModuleWithUnsavedCart();

        TinyAssert::same(
            null,
            $module->readTwoCartScopedCompany(),
            'a fresh unsaved cart has no id to match against, so the record must read absent'
        );
        TinyAssert::true(
            isset($cookie->two_company_id),
            'a request whose cart is unsaved must not destroy a record it cannot judge'
        );
    }

    /**
     * The other half of the no-cart contract: the WRITER declines too.
     *
     * A record stamped 0 is unreadable by construction - the reader only returns
     * one whose stamp equals the current cart id, which is always > 0 - and the
     * first read that DOES have a cart would clear it as belonging to another
     * cart, taking whatever was already there with it. So no cart means write
     * nothing and clear nothing, on both no-cart shapes.
     *
     * Reachable through hookActionCustomerAddressSave() on the My-Account address
     * page, where the buyer need not have a cart at all.
     */
    private static function testNoCartWritesNothingAndClearsNothing(): void
    {
        foreach (['no cart object at all' => false, 'a fresh unsaved cart' => true] as $shape => $unsaved) {
            $cookie = self::seedSessionCompany();
            $module = $unsaved ? self::makeModuleWithUnsavedCart() : self::makeModule(0);

            $module->storeTwoCartScopedCompany(['name' => 'Late Arrival Ltd', 'id' => '99999999']);

            TinyAssert::false(
                isset($cookie->{'two_company_name'}) && (string) $cookie->two_company_name === 'Late Arrival Ltd',
                'with ' . $shape . ' the writer must store nothing - a record it cannot stamp is unreadable'
            );
            TinyAssert::same(
                'Example Trading Ltd',
                (string) $cookie->two_company_name,
                'with ' . $shape . ' the existing record must survive untouched'
            );
            TinyAssert::same(
                '12345678',
                (string) $cookie->two_company_id,
                'with ' . $shape . ' the existing organisation number must survive untouched'
            );
            TinyAssert::same(
                (string) self::CART_ID,
                (string) $cookie->two_company_cart_id,
                'with ' . $shape . ' the existing stamp must not be overwritten with 0'
            );
        }
    }

    /**
     * Centralising the keys exists to stop a write site inventing its own field
     * name. A mistyped one used to be skipped in silence, which is the exact drift
     * the constant was introduced to catch - so it is reported.
     */
    private static function testUnknownWriteFieldIsReported(): void
    {
        $cookie = self::seedSessionCompany();
        $module = self::makeModule(self::CART_ID);
        PrestaShopLogger::reset();

        $module->storeTwoCartScopedCompany(['addressId' => '4242']);

        TinyAssert::same(
            (string) self::ADDRESS_ID,
            (string) $cookie->two_company_address_id,
            'a bogus field name must not be mistaken for the address marker'
        );

        $reported = false;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'addressId') !== false && $entry['severity'] >= 2) {
                $reported = true;
                break;
            }
        }
        TinyAssert::true(
            $reported,
            'an unrecognised company session field must be reported by name, not silently dropped'
        );
    }

    /* ---- TWO-40 regression cover: both existing guards still fire ---- */

    /**
     * Cart scoping is an extra axis, not a replacement. A buyer can change the
     * address country inside ONE cart, so the country-mismatch wipe still has to
     * fire on a record whose cart stamp matches perfectly.
     */
    private static function testCountryMismatchStillWipesTheRecord(): void
    {
        $cookie = self::seedSessionCompany();
        $module = self::makeModule(self::CART_ID);

        $validated = $module->getTwoValidatedSessionCompanyData('ES');

        TinyAssert::same('', (string) $validated['company_name'], 'a GB company must not survive an ES address');
        TinyAssert::same('', (string) $validated['organization_number']);
        foreach (self::COMPANY_COOKIE_KEYS as $key) {
            TinyAssert::false(
                isset($cookie->{$key}),
                'the country-mismatch wipe must still clear ' . $key
            );
        }
    }

    /**
     * The other guard: a record with a company and a number but no country marker
     * cannot be reused safely against a known address country, so it is wiped.
     * Stamped with the current cart, so only the country guard can be what fires.
     */
    private static function testLegacyRecordWithoutCountryMarkerStillWipesTheRecord(): void
    {
        $cookie = self::seedSessionCompany();
        unset($cookie->two_company_country);
        $module = self::makeModule(self::CART_ID);

        $validated = $module->getTwoValidatedSessionCompanyData('GB');

        TinyAssert::same(
            '',
            (string) $validated['company_name'],
            'a record with no country marker must not be reused against a known address country'
        );
        TinyAssert::false(
            isset($cookie->two_company_id),
            'the no-country-marker guard must still clear the record'
        );
    }

    /**
     * And the guards must not have become unconditional: a matching country on a
     * matching cart still returns the company.
     */
    private static function testMatchingCountryStillReturnsTheRecord(): void
    {
        self::seedSessionCompany();
        $module = self::makeModule(self::CART_ID);

        $validated = $module->getTwoValidatedSessionCompanyData('GB');

        TinyAssert::same('Example Trading Ltd', (string) $validated['company_name']);
        TinyAssert::same('12345678', (string) $validated['organization_number']);
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

    /**
     * TWO-25288 boundary, pinned from the empty-number side. The guard's job is
     * to disown a session organisation NUMBER; a record with no number carries
     * nothing to disown, so it must not fire here even though the address's
     * company name has just changed underneath it.
     *
     * storeCompanyDataInSession() writes exactly this shape - a resolved company
     * NAME with no organisation number - whenever a buyer types a company
     * without picking one from the register. This is that record reaching the
     * address-save hook.
     *
     * Paired with testAddressSaveDropsTheNumberOfADisownedCompany(), which pins
     * the other side of the same boundary: a record that DOES carry a real
     * organisation number is still disowned on a mismatched name. Together they
     * fix where the guard fires and where it does not - the two conditions are
     * NOT equivalent, and this pair is what makes that a checked fact rather
     * than a claim in a comment.
     */
    private static function testAddressSaveKeepsCountryMarkerWhenNumberWasAlreadyEmpty(): void
    {
        $cookie = self::seedSessionCompany();
        $cookie->two_company_id = '';
        PrestaShopLogger::reset();

        self::runAddressSave('A Different Trading Name', '');

        TinyAssert::true(
            isset($cookie->two_company_country),
            'a country marker with no organisation number behind it was not this guard\'s to clear'
        );
        TinyAssert::same('GB', (string) $cookie->two_company_country);
        TinyAssert::same('A Different Trading Name', (string) $cookie->two_company_name);

        foreach (PrestaShopLogger::$logs as $entry) {
            TinyAssert::false(
                strpos($entry['message'], 'Dropped session company number') !== false,
                'nothing was dropped - an empty number was never disowned, so this log line must not fire'
            );
        }
    }

    /* ---- fixtures ---- */

    /**
     * A session carrying a completed company selection, exactly the keys
     * ajaxProcessSaveCompany() writes.
     *
     * @param int|null $cartId the cart the record is stamped with (TWO-40), or
     *                         null to seed a record with no stamp at all - what a
     *                         cookie written before TWO-40 looks like
     */
    private static function seedSessionCompany($cartId = self::CART_ID): Cookie
    {
        $cookie = new Cookie();
        $cookie->two_company_name = 'Example Trading Ltd';
        $cookie->two_company_id = '12345678';
        $cookie->two_company_country = 'GB';
        $cookie->two_company_address_id = (string) self::ADDRESS_ID;
        if ($cartId !== null) {
            $cookie->two_company_cart_id = (string) $cartId;
        }
        Context::getContext()->cookie = $cookie;
        self::attachCart(self::CART_ID);

        return $cookie;
    }

    /**
     * Put a loaded cart of the given id on the shared context, or clear the cart
     * entirely when the id is 0.
     */
    private static function attachCart(int $cartId): void
    {
        if ($cartId <= 0) {
            Context::getContext()->cart = null;

            return;
        }

        StubStore::$carts[$cartId] = ['id_address_invoice' => self::ADDRESS_ID];
        Context::getContext()->cart = new Cart($cartId);
    }

    /**
     * A module bound to the shared context, running against the given cart.
     */
    private static function makeModule(int $cartId): TwopaymentTestHarness
    {
        self::attachCart($cartId);

        // The harness binds itself to the shared context, so it sees the cookie
        // and cart seeded above.
        return new TwopaymentTestHarness();
    }

    /**
     * A module whose context carries a Cart OBJECT with no id - a cart the buyer
     * has not put anything in yet. Distinct from makeModule(0), which removes the
     * cart from the context entirely: this is the shape that reaches the module
     * far more often, and only the id can be what makes it a no-cart request.
     */
    private static function makeModuleWithUnsavedCart(): TwopaymentTestHarness
    {
        $cart = new Cart(0);
        Context::getContext()->cart = $cart;

        // Pin the fixture's own shape. A double that reported this as a loaded
        // object could not express an unsaved cart at all, and these cases would
        // quietly become a second copy of the no-cart-object ones.
        TinyAssert::true(is_object($cart), 'the unsaved-cart fixture must still put a Cart on the context');
        TinyAssert::false(
            Validate::isLoadedObject($cart),
            'a cart with no id is not a loaded object - the fixture cannot express the shape under test'
        );

        return new TwopaymentTestHarness();
    }

    /**
     * A controller ready to serve ajaxProcessCheckOrderIntent(), on a session
     * carrying an UNSTAMPED company record - so the stamp asserted afterwards can
     * only have come from the handler's own store-back.
     *
     * The posted company and organisation number are the handler's highest-priority
     * company source, which keeps this fixture to the gates the handler insists on
     * (a loaded customer, currency and invoice address) rather than reproducing the
     * whole address-derivation fallback chain.
     */
    private static function makeOrderIntentController()
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        self::seedSessionCompany(null);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Tools::setTestValue('ajax', 1);
        Tools::setTestValue('action', 'checkOrderIntent');
        Tools::setTestValue('token', Tools::getToken(false));
        Tools::setTestValue('id_address_invoice', self::ADDRESS_ID);
        Tools::setTestValue('company', 'Intent Trading Ltd');
        Tools::setTestValue('companyid', '11223344');

        StubStore::$currencies[self::CURRENCY_ID] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$countries[self::COUNTRY_ID] = 'GB';
        StubStore::$addresses[self::ADDRESS_ID] = [
            'id_country' => self::COUNTRY_ID,
            'company' => 'Intent Trading Ltd',
            'dni' => '',
            'address1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'phone' => '+447000000000',
            'loaded' => true,
        ];
        StubStore::$customers[self::CUSTOMER_ID] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Sam',
            'lastname' => 'Reed',
            'secure_key' => 'secure-key-9812',
            'loaded' => true,
        ];
        // Set AFTER seedSessionCompany(), whose attachCart() replaces this entry
        // with a minimal one.
        StubStore::$carts[self::CART_ID] = [
            'id_customer' => self::CUSTOMER_ID,
            'id_currency' => self::CURRENCY_ID,
            'id_address_invoice' => self::ADDRESS_ID,
            'id_address_delivery' => self::ADDRESS_ID,
            'id_carrier' => 0,
            'id_lang' => 1,
        ];
        Context::getContext()->cart = new Cart(self::CART_ID);

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
        $controller->module = new class extends TwopaymentTestHarness {
            /** Keeps the payload build off the network - the stamp is what is under test. */
            public function getTwoIntentOrderData($cart, $customer, $currency, $address)
            {
                return [
                    'gross_amount' => '120.00',
                    'net_amount' => '100.00',
                    'tax_amount' => '20.00',
                    'discount_amount' => '0.00',
                    'currency' => 'GBP',
                    'invoice_type' => 'FUNDED_INVOICE',
                    'buyer' => [
                        'company' => [
                            'company_name' => (string) $address->company,
                            'country_prefix' => 'GB',
                            'organization_number' => (string) $address->companyid,
                            'website' => '',
                        ],
                    ],
                    'billing_address' => ['country' => 'GB', 'city' => 'London'],
                    'shipping_address' => ['country' => 'GB', 'city' => 'London'],
                    'line_items' => [],
                ];
            }
        };

        return $controller;
    }

    /**
     * Fire the actionCustomerAddressSave hook the way core does when the buyer
     * saves the address form.
     *
     * @param string $company   the company name on the saved address
     * @param string $companyId the hidden organisation-number field in the POST
     */
    private static function runAddressSave(string $company, string $companyId, int $cartId = self::CART_ID): void
    {
        Tools::resetTestValues();
        if ($companyId !== '') {
            Tools::setTestValue('companyid', $companyId);
        }

        $address = new stdClass();
        $address->company = $company;
        $address->id = self::ADDRESS_ID;

        $module = self::makeModule($cartId);
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

    private static function makeController(string $token, string $action = 'clearCompany')
    {
        PrestaShopLogger::reset();
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
