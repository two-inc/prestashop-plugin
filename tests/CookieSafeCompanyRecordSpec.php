<?php

declare(strict_types=1);

/**
 * TWO-26094: the company and mirror-write records survive any value core's
 * cookie would refuse, and a write never leaves a half-updated record behind.
 *
 * Core's Cookie::__set throws on '|' or '¤' (classes/Cookie.php, 1.7.6 to 9),
 * yet core's own isGenericName lets '|' into a company name.
 */
final class CookieSafeCompanyRecordSpec
{
    private const CART_ID = 7701;

    public static function runAll(): void
    {
        $legacyCompany = [
            'two_company_name' => 'Legacy Trading Ltd',
            'two_company_id' => '11111111',
            'two_company_country' => 'GB',
            'two_company_address_id' => '42',
            'two_company_cart_id' => (string) self::CART_ID,
        ];
        $legacyMirror = [
            'two_mirror_company' => 'Legacy Trading Ltd',
            'two_mirror_address1' => '1 Old Street',
            'two_mirror_cart_id' => (string) self::CART_ID,
        ];
        $mirror = [
            'company' => 'Pipe | Works Ltd',
            'organization' => '12345678',
            'country' => 'GB',
            'address1' => 'Unit 4 | Dock ¤ Road',
            'address2' => '',
            'postcode' => 'EC1A 1BB',
            'city' => 'London',
            'state' => '',
        ];

        // [record, raw legacy seed, first write, second write, expected read, description]
        $cases = [
            ['company', [], [], ['name' => 'Smith | Sons Ltd', 'id' => '123'], ['name' => 'Smith | Sons Ltd', 'id' => '123'], 'company name with |'],
            ['company', [], [], ['name' => 'Kr¤na AB', 'id' => '456'], ['name' => 'Kr¤na AB', 'id' => '456'], 'company name with ¤'],
            ['company', [], [], ['name' => 'Acme Trading Ltd', 'id' => '789', 'country' => 'GB'], ['name' => 'Acme Trading Ltd', 'id' => '789', 'country' => 'GB'], 'normal name'],
            ['mirror', [], [], $mirror, $mirror, 'mirror record round-trip'],
            ['company', $legacyCompany, [], [], null, 'old raw-format company record present'],
            ['mirror', $legacyMirror, [], [], null, 'old raw-format mirror record present'],
            ['company', $legacyCompany, [], ['name' => 'Fresh Ltd'], ['name' => 'Fresh Ltd', 'id' => ''], 'write over an old raw-format record'],
            ['company', [], ['name' => 'Old Ltd', 'id' => '111'], ['id' => '222', 'name' => 'New | Ltd'], ['name' => 'New | Ltd', 'id' => '222'], 'partial-write safety: company'],
            ['mirror', [], ['company' => 'Old Ltd', 'city' => 'Leeds'], ['city' => 'York', 'company' => 'New ¤ Ltd'], ['company' => 'New ¤ Ltd', 'city' => 'York'], 'partial-write safety: mirror'],
        ];

        $failures = [];
        foreach ($cases as [$record, $seed, $first, $second, $expected, $description]) {
            try {
                self::runCase($record, $seed, $first, $second, $expected, $description);
            } catch (Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        TinyAssert::true($failures === [], "failing rows:\n" . implode("\n", $failures));
    }

    private static function runCase(string $record, array $seed, array $first, array $second, $expected, string $description): void
    {
        $cookie = new Cookie();
        foreach ($seed as $key => $value) {
            $cookie->{$key} = $value;
        }
        Context::getContext()->cookie = $cookie;
        StubStore::$carts[self::CART_ID] = ['id_address_invoice' => 42];
        Context::getContext()->cart = new Cart(self::CART_ID);
        $module = new TwopaymentTestHarness();
        $store = $record === 'company' ? 'storeTwoCartScopedCompany' : 'storeTwoCartScopedMirrorWrites';
        $read = $record === 'company' ? 'readTwoCartScopedCompany' : 'readTwoCartScopedMirrorWrites';

        $thrown = '';
        try {
            foreach ([$first, $second] as $fields) {
                if ($fields !== []) {
                    $module->{$store}($fields);
                }
            }
        } catch (Exception $e) {
            $thrown = $e->getMessage();
        }

        $actual = $module->{$read}();
        if ($expected === null) {
            TinyAssert::same(null, $actual, 'an old raw-format record must read as absent: ' . $description);
        } else {
            TinyAssert::true(is_array($actual), 'record must be readable: ' . $description);
            TinyAssert::same($expected, array_intersect_key((array) $actual, $expected), 'record must read back whole: ' . $description);
        }
        TinyAssert::same('', $thrown, 'no write may throw: ' . $description);
        foreach (array_keys($seed) as $key) {
            TinyAssert::false(isset($cookie->{$key}), 'old raw-format key must be purged: ' . $key . ': ' . $description);
        }
    }
}

