<?php

declare(strict_types=1);

/**
 * TWO-25387 - the native per-module country allowlist was not enforced at
 * display time.
 *
 * PrestaShop lets a merchant restrict an individual payment module to a subset
 * of countries from the back office's Payment Restrictions screen; the rows land
 * in `module_country`, one per (module, shop, country). That allowlist is
 * separate from the shop's own active-country list.
 *
 * Core applies it at final order submission against the cart's INVOICE address -
 * the check controllers/front/payment.php defers to core for. Its display-time
 * filter matches the same table against the CONTEXTUAL country, which the front
 * controller resolves from whichever address `PS_TAX_ADDRESS_TYPE` names, and
 * that defaults to the DELIVERY address (verified on a stock PrestaShop 8
 * install). So on any cart whose delivery and invoice countries differ, core
 * rendered the Two tile against one country, took the buyer through the whole
 * payment step, and refused the order against the other at the last click. That
 * is a materially worse outcome than the currency check next to it, which
 * withholds the tile.
 *
 * Contract pinned here:
 *
 *  - A billing country outside the allowlist withholds the payment option, and
 *    says so in the log - exactly like an unsupported currency - once per
 *    request rather than once per evaluation.
 *  - A billing country inside the allowlist still offers it.
 *  - The BILLING (invoice) address decides, not the delivery address, because
 *    the billing address is what core matches on at submission. A gate that
 *    disagreed with core would just relocate the dead end.
 *  - The allowlist is scoped to THIS module and THIS shop - both are read from
 *    the running instance, not assumed to be 1.
 *  - A genuinely empty allowlist withholds the option, because core would refuse
 *    the submission too. So does an address carrying no country at all.
 *  - The lookup carries no `LIMIT 1`, because Db::getValue() appends its own.
 *    Getting that wrong made the query a syntax error and, under the
 *    fail-closed branch, silently removed Two from every shop - caught only by
 *    the Playwright e2e job. Pinned here so it cannot come back.
 *  - A lookup that could not be answered at all fails OPEN, because a thrown DB
 *    error is not a restriction verdict.
 */
final class PaymentCountryRestrictionSpec
{
    /** ps_country id fixtures - see StubStore::$countries. */
    private const GB = 826;
    private const NO = 47;
    private const ES = 34;

    public static function runAll(): void
    {
        self::testCountryOutsideTheAllowlistWithholdsThePaymentOption();
        self::testCountryInsideTheAllowlistKeepsThePaymentOption();
        self::testWithholdingForCountryIsLoggedAndWithholds();
        self::testWithholdReasonIsLoggedOncePerRequestNotPerCall();
        self::testTheBillingAddressDecidesNotTheDeliveryAddress();
        self::testTheAllowlistIsScopedToTheRunningShop();
        self::testTheAllowlistIsScopedToThisModule();
        self::testAnEmptyAllowlistWithholdsThePaymentOption();
        self::testAnAddressWithNoCountryWithholdsThePaymentOption();
        self::testEveryActiveCountryEnabledIsUnaffected();
        self::testTheLookupDoesNotCarryItsOwnLimitClause();
        self::testAnUnanswerableLookupFailsOpen();
        self::testALookupThatFailsWithoutThrowingAlsoFailsOpen();
        self::testTheFailOpenReasonIsLoggedOncePerRequestAndCarriesNoSql();
        self::testAnAddressWithNoCountryIsNotReportedAsADisallowedCountry();
        self::testAPassedAddressThatIsNotTheInvoiceAddressIsIgnored();
    }

    /* ===================================================================
     * Harness
     * =================================================================== */

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        PrestaShopLogger::reset();
    }

    /**
     * A module whose cart clears every OTHER hookPaymentOptions gate, so the
     * only thing left that can withhold the option is the country allowlist.
     * Billing and delivery both resolve to $idCountry unless a spec splits them.
     */
    private static function offerableModule(int $idCountry): object
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        // Without this the payment-tile render emits an undefined-property
        // warning on every call, which is noise the next real one hides in.
        $module->_path = '/modules/twopayment/';

        // ISO codes for the fixture ids: the hook also withholds the option
        // when no ISO country resolves from either address, which would refuse
        // every case here for a reason this spec is not about.
        StubStore::$countries = [self::GB => 'gb', self::NO => 'no', self::ES => 'es'];

        StubStore::$addresses[904] = [
            'id_country' => $idCountry,
            'company' => 'Example Trading Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];
        StubStore::$currencies[826] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 826]];

        $cart = new Cart(7387);
        $cart->id_address_invoice = 904;
        $cart->id_address_delivery = 904;
        $cart->id_currency = 826;
        $module->context->cart = $cart;

        return $module;
    }

    /**
     * `module_country` rows for the module and shop the given instance actually
     * reports, so a fixture cannot silently disagree with the code under test.
     */
    private static function allow(object $module, int ...$countries): array
    {
        $rows = [];
        foreach ($countries as $idCountry) {
            $rows[] = [
                'id_module' => (int) $module->id,
                'id_shop' => (int) $module->context->shop->id,
                'id_country' => $idCountry,
            ];
        }

        return $rows;
    }

    private static function countryLogLines(): int
    {
        $lines = 0;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'not enabled for this module') !== false) {
                ++$lines;
            }
        }

        return $lines;
    }

    private static function lookupFailureLines(): int
    {
        $lines = 0;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'module_country lookup failed') !== false) {
                ++$lines;
            }
        }

        return $lines;
    }

    /** The module_country lookups Db::getValue() was asked for. */
    private static function countryLookups(): array
    {
        $found = [];
        foreach (StubStore::$dbLastGetValue as $sql) {
            if (strpos($sql, 'module_country') !== false) {
                $found[] = $sql;
            }
        }

        return $found;
    }

    /* ===================================================================
     * Gate
     * =================================================================== */

    private static function testCountryOutsideTheAllowlistWithholdsThePaymentOption(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow($module, self::NO, self::ES);

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'a billing country outside the module allowlist must withhold the payment option'
        );
    }

    private static function testCountryInsideTheAllowlistKeepsThePaymentOption(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow($module, self::NO, self::GB, self::ES);

        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'a billing country inside the module allowlist must still be offered Two'
        );
    }

    /**
     * A payment method that vanishes without a trace is the failure mode the
     * currency gate next to this one already logs its way out of. Asserts the
     * withholding too - a module that logged and still returned the tile would
     * otherwise satisfy this.
     */
    private static function testWithholdingForCountryIsLoggedAndWithholds(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow($module, self::NO);
        PrestaShopLogger::reset();

        TinyAssert::same(0, count($module->hookPaymentOptions([])), 'the option must be withheld');
        TinyAssert::same(1, self::countryLogLines(), 'hiding the payment option must say why in the log');

        $logged = '';
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'not enabled for this module') !== false) {
                $logged = $entry['message'];
            }
        }
        TinyAssert::true(
            strpos($logged, (string) self::GB) !== false,
            'the log must name the country that was refused, or it cannot be diagnosed'
        );
    }

    /**
     * Core asks for payment options several times per payment-step render, and a
     * narrow allowlist is a PERMANENT setting - so every out-of-allowlist buyer
     * would otherwise write several ps_log rows per render, burying the next
     * real line in the module's own repetition.
     */
    private static function testWithholdReasonIsLoggedOncePerRequestNotPerCall(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow($module, self::NO);
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);

        TinyAssert::same(1, self::countryLogLines(), 'the withhold reason must be logged once per request');
    }

    /**
     * The whole point of the gate is to agree with the submission check, and
     * core matches `module_country` against the INVOICE address country there.
     * A delivery-address gate would offer the tile on carts core then refuses -
     * the exact bug, just reshaped. Core's own display filter gets this wrong,
     * which is why the bug existed.
     */
    private static function testTheBillingAddressDecidesNotTheDeliveryAddress(): void
    {
        $module = self::offerableModule(self::GB);
        // Delivery in the allowlist, billing outside it.
        StubStore::$addresses[905] = ['id_country' => self::NO, 'loaded' => true];
        $module->context->cart->id_address_delivery = 905;
        StubStore::$moduleCountries = self::allow($module, self::NO);

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an allowlisted DELIVERY country must not rescue a billing country core will refuse'
        );

        // And the mirror image, so this is not passing for some unrelated reason.
        $module = self::offerableModule(self::NO);
        StubStore::$addresses[905] = ['id_country' => self::GB, 'loaded' => true];
        $module->context->cart->id_address_delivery = 905;
        StubStore::$moduleCountries = self::allow($module, self::NO);

        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'an allowlisted BILLING country must be offered Two whatever the delivery country is'
        );
    }

    /**
     * `module_country` is keyed by shop as well as module, and the shop must be
     * READ from the running context rather than assumed. Driven on a shop whose
     * id is deliberately not 1, so a hardcoded 1 cannot pass.
     */
    private static function testTheAllowlistIsScopedToTheRunningShop(): void
    {
        $module = self::offerableModule(self::GB);
        $module->context->shop->id = 4;
        StubStore::$moduleCountries = self::allow($module, self::GB);
        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'a row for the RUNNING shop must enable the country'
        );

        $module = self::offerableModule(self::GB);
        $module->context->shop->id = 4;
        // Same country, same module, a different shop's row.
        StubStore::$moduleCountries = [
            ['id_module' => (int) $module->id, 'id_shop' => 9, 'id_country' => self::GB],
        ];
        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an allowlist row for a different shop must not enable the country on this one'
        );
    }

    /**
     * Likewise the module id: another payment module's allowlist says nothing
     * about this one. Driven on a module id that is deliberately not 1.
     */
    private static function testTheAllowlistIsScopedToThisModule(): void
    {
        $module = self::offerableModule(self::GB);
        $module->id = 12;
        StubStore::$moduleCountries = self::allow($module, self::GB);
        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'a row for THIS module must enable the country'
        );

        $module = self::offerableModule(self::GB);
        $module->id = 12;
        StubStore::$moduleCountries = [
            ['id_module' => 77, 'id_shop' => (int) $module->context->shop->id, 'id_country' => self::GB],
        ];
        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            "another module's allowlist row must not enable the country for this one"
        );
    }

    /**
     * Fail-closed, deliberately: with no rows at all core's submission check
     * finds nothing to match either, so offering the tile would only move the
     * same refusal to the last click.
     */
    private static function testAnEmptyAllowlistWithholdsThePaymentOption(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = [];

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an empty module_country allowlist must withhold the payment option'
        );
    }

    /**
     * A loaded address carrying no country cannot be matched against the
     * allowlist, and core would match it against id_country 0 and refuse. The
     * one fail-closed guard reachable through hookPaymentOptions - the earlier
     * gates already cover an unloaded cart or address.
     */
    private static function testAnAddressWithNoCountryWithholdsThePaymentOption(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$addresses[904]['id_country'] = 0;
        StubStore::$moduleCountries = self::allow($module, self::GB, self::NO, self::ES);

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an address with no country must withhold the payment option'
        );
    }

    /**
     * The shop that has never touched the Payment Restrictions screen: a row per
     * active country, which is what PaymentModule::install() writes. Seeded as
     * REAL rows rather than leaning on the harness's unrestricted default, so
     * this asserts module behaviour and not a stub branch. Nothing about this
     * change may narrow such a shop.
     */
    private static function testEveryActiveCountryEnabledIsUnaffected(): void
    {
        foreach ([self::GB, self::NO, self::ES] as $idCountry) {
            $module = self::offerableModule($idCountry);
            StubStore::$moduleCountries = self::allow($module, self::GB, self::NO, self::ES);
            TinyAssert::same(
                1,
                count($module->hookPaymentOptions([])),
                'country ' . $idCountry . ' must still be offered Two when every active country is enabled'
            );
        }
    }

    /**
     * checkCountry() accepts an already-loaded Address so the hook does not
     * hydrate a second copy per render - but the verdict INVERTS if that copy is
     * the delivery address, and the gate's whole purpose is agreeing with what
     * core checks at submission. So the passed object is verified to be this
     * cart's invoice address rather than trusted.
     *
     * Driven directly: every path through hookPaymentOptions() passes the right
     * address by construction, so a spec that went through the hook could never
     * catch a future caller getting this wrong.
     */
    private static function testAPassedAddressThatIsNotTheInvoiceAddressIsIgnored(): void
    {
        $module = self::offerableModule(self::GB);
        // The cart's real invoice address is 904/GB, which is NOT allowlisted.
        StubStore::$moduleCountries = self::allow($module, self::NO);
        StubStore::$addresses[905] = ['id_country' => self::NO, 'loaded' => true];

        $method = new ReflectionMethod(Twopayment::class, 'checkCountry');
        $method->setAccessible(true);

        TinyAssert::same(
            false,
            $method->invoke($module, $module->context->cart, new Address(905)),
            'a passed address that is not the invoice address must be ignored, not trusted'
        );

        // The genuine invoice address is still accepted, so the fast path works.
        StubStore::$moduleCountries = self::allow($module, self::GB);
        TinyAssert::same(
            true,
            $method->invoke($module, $module->context->cart, new Address(904)),
            "the cart's own invoice address must be accepted"
        );
    }

    /**
     * Core's Db::getValue() delegates to getRow(), which appends its OWN
     * ' LIMIT 1'; its docblock documents the argument as "the select query
     * (without LIMIT 1)". The first cut of this gate supplied one anyway, so
     * every lookup was `LIMIT 1 LIMIT 1` - a MariaDB syntax error that the
     * fail-closed branch turned into Two silently disappearing from every shop.
     * Only the Playwright e2e job caught it. This asserts the emitted SQL
     * directly, because the fail-open path now swallows the exception and would
     * otherwise let the same mistake back through green.
     */
    private static function testTheLookupDoesNotCarryItsOwnLimitClause(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow($module, self::GB);
        $module->hookPaymentOptions([]);

        $lookups = self::countryLookups();
        TinyAssert::true($lookups !== [], 'the gate must actually query module_country');
        foreach ($lookups as $sql) {
            TinyAssert::true(
                preg_match('/\bLIMIT\b/i', $sql) !== 1,
                'Db::getValue() appends its own LIMIT 1 - the query must not carry one: ' . $sql
            );
        }
    }

    /**
     * A lookup that raises is not a restriction verdict. Treating it as one
     * hides Two on every shop at once over a fault that has nothing to do with
     * the merchant's country settings - which is exactly what happened.
     */
    private static function testAnUnanswerableLookupFailsOpen(): void
    {
        $module = self::offerableModule(self::GB);
        // The gate's own lookup raises; every other Db::getValue() still works.
        StubStore::$moduleCountries = self::allow($module, self::NO);
        StubStore::$dbGetValueThrowsOn = 'module_country';
        PrestaShopLogger::reset();

        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'a lookup that could not be answered must not withhold the payment option'
        );
        TinyAssert::same(1, self::lookupFailureLines(), 'failing open must be logged - the restriction is NOT enforced');

        StubStore::$dbGetValueThrowsOn = null;
    }

    /**
     * The case the throwing fixture CANNOT show, and the one that matters most:
     * on the module's declared floor (ps_versions_compliancy min 1.7.6.0) a
     * failed query does not throw at all - DbPDO::_query() is a bare
     * link->query(), and DbMySQLi's is unwrapped too - so Db::getValue() just
     * returns false, exactly like a genuine "no such row". A gate that reads
     * that as a restriction verdict removes Two from every shop over a fault
     * that has nothing to do with the merchant's country settings. Which is
     * precisely what the redundant LIMIT 1 did.
     */
    private static function testALookupThatFailsWithoutThrowingAlsoFailsOpen(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow($module, self::NO);
        StubStore::$dbGetValueSilentErrorOn = 'module_country';
        PrestaShopLogger::reset();

        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'a silently-failed lookup must not be read as a country restriction'
        );
        TinyAssert::same(1, self::lookupFailureLines(), 'failing open must be logged');

        StubStore::$dbGetValueSilentErrorOn = null;
    }

    /**
     * A failing lookup fails on EVERY evaluation, forever - it floods harder
     * than any withhold. And the driver's own message carries the SQL, which the
     * payment controller already refuses to put anywhere a log reader picks it
     * up, so the reason must be the exception class or errno instead.
     */
    private static function testTheFailOpenReasonIsLoggedOncePerRequestAndCarriesNoSql(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow($module, self::NO);
        StubStore::$dbGetValueSilentErrorOn = 'module_country';
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);

        TinyAssert::same(1, self::lookupFailureLines(), 'the fail-open reason must be logged once per request');

        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'module_country lookup failed') === false) {
                continue;
            }
            TinyAssert::true(
                strpos($entry['message'], 'SELECT') === false
                && strpos($entry['message'], 'ps_module_country') === false,
                'the log must not carry the SQL: ' . $entry['message']
            );
        }

        StubStore::$dbGetValueSilentErrorOn = null;
    }

    /**
     * "billing country 0 not enabled for this module" sends whoever reads it to
     * the Payment Restrictions screen to look for a country that was never
     * there. The address has no country; that is a different fault.
     */
    private static function testAnAddressWithNoCountryIsNotReportedAsADisallowedCountry(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$addresses[904]['id_country'] = 0;
        StubStore::$moduleCountries = self::allow($module, self::GB);
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);

        $logged = '';
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'Payment option hidden') !== false) {
                $logged = $entry['message'];
            }
        }
        TinyAssert::true($logged !== '', 'the withholding must be logged');
        TinyAssert::true(
            strpos($logged, 'no country') !== false,
            'the log must say the address has no country, not that country 0 is disallowed: ' . $logged
        );
        TinyAssert::true(
            strpos($logged, 'country 0') === false,
            'the log must not report a country id of 0 as a disallowed country: ' . $logged
        );
    }
}
