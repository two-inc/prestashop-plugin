<?php

declare(strict_types=1);

/**
 * TWO-26094: the company and mirror-write records live server-side, keyed by
 * cart and shop, so nothing a buyer types can break or overflow the cookie.
 *
 * Core's Cookie::__set throws on '|' or '¤' (classes/Cookie.php, 1.7.6 to 9),
 * and PS 8/9 Cookie::write throws past 4096 bytes.
 */
final class CartRecordStorageSpec
{
    private const CART_A = 7701;

    private const CART_B = 7702;

    public static function runAll(): void
    {
        $nordic = static function (int $length): string {
            return mb_substr(str_repeat('æøåäöÆØÅÄÖ', 26), 0, $length);
        };
        // Address field sizes from core's Address and State definitions.
        $maxMirror = [
            'company' => $nordic(255),
            'organization' => '1234567890123456',
            'country' => 'NO',
            'address1' => $nordic(128),
            'address2' => $nordic(128),
            'postcode' => '123456789012',
            'city' => $nordic(64),
            'state' => $nordic(80),
        ];
        $maxCompany = ['name' => $nordic(255), 'id' => '1234567890123456', 'country' => 'NO', 'address_id' => '99999999'];
        $company = ['name' => 'Acme Trading Ltd', 'id' => '789', 'country' => 'GB', 'address_id' => '42'];
        $mirror = ['company' => 'Pipe | Works Ltd', 'address1' => 'Unit 4 | Dock ¤ Road', 'city' => 'London'];
        $legacyCookie = [
            'two_company_name' => 'Legacy Trading Ltd',
            'two_company_id' => '11111111',
            'two_company_cart_id' => (string) self::CART_A,
            'two_mirror_company' => 'Legacy Trading Ltd',
            'two_mirror_cart_id' => (string) self::CART_A,
        ];
        $a = ['cart', self::CART_A];
        $b = ['cart', self::CART_B];

        // [cookie seed, steps, description]; a read step's expected fields must match, null means absent.
        $cases = [
            [[], [$a, ['store', 'company', ['name' => 'Smith | Sons Ltd', 'id' => '123']], ['read', 'company', ['name' => 'Smith | Sons Ltd', 'id' => '123']]], 'company name with |'],
            [[], [$a, ['store', 'company', ['name' => 'Kr¤na AB', 'id' => '456']], ['read', 'company', ['name' => 'Kr¤na AB', 'id' => '456']]], 'company name with ¤'],
            [[], [$a, ['store', 'company', $maxCompany], ['store', 'mirror', $maxMirror], ['read', 'company', $maxCompany], ['read', 'mirror', $maxMirror]], 'max-length Nordic company and mirror together'],
            [[], [$a, ['store', 'company', $company], ['store', 'mirror', $mirror], ['read', 'company', $company], ['read', 'mirror', $mirror]], 'round-trip of both records'],
            [[], [$a, ['store', 'company', $company], ['store', 'company', ['id' => '222', 'country' => null]], ['read', 'company', ['name' => 'Acme Trading Ltd', 'id' => '222', 'country' => '']]], 'partial write merges, null removes a field'],
            [[], [$a, ['store', 'company', $company], $b, ['read', 'company', null], ['store', 'company', ['name' => 'Other Ltd']], $a, ['read', 'company', $company]], 'cart isolation: cart B cannot read cart A, nor overwrite it'],
            [[], [$a, ['store', 'company', $company], ['store', 'mirror', $mirror], ['order', self::CART_A], ['read', 'company', null], ['read', 'mirror', null]], 'cleared once the cart is ordered'],
            [$legacyCookie, [$a, ['read', 'company', null], ['read', 'mirror', null]], 'old-format cookie record present: absent and purged'],
            [[], [$a, ['store', 'company', $company], ['store', 'company', ['name' => "Bad \xB1 Ltd"]], ['read', 'company', null]], 'unencodable write clears the record'],
        ];

        $failures = [];
        foreach ($cases as [$seed, $steps, $description]) {
            try {
                self::runCase($seed, $steps, $description);
            } catch (Throwable $e) {
                $failures[] = $description . ': ' . $e->getMessage();
            }
        }
        TinyAssert::true($failures === [], "failing rows:\n" . implode("\n", $failures));
    }

    private static function runCase(array $seed, array $steps, string $description): void
    {
        StubStore::$cartRecords = [];
        $cookie = new Cookie();
        foreach ($seed as $key => $value) {
            $cookie->{$key} = $value;
        }
        Context::getContext()->cookie = $cookie;
        $module = new TwopaymentTestHarness();

        foreach ($steps as $step) {
            if ($step[0] === 'cart') {
                StubStore::$carts[$step[1]] = ['id_address_invoice' => 42];
                Context::getContext()->cart = new Cart($step[1]);
            } elseif ($step[0] === 'order') {
                $module->clearTwoCartRecords($step[1]);
            } elseif ($step[0] === 'store') {
                $module->{$step[1] === 'company' ? 'storeTwoCartScopedCompany' : 'storeTwoCartScopedMirrorWrites'}($step[2]);
            } else {
                $actual = $module->{$step[1] === 'company' ? 'readTwoCartScopedCompany' : 'readTwoCartScopedMirrorWrites'}();
                $expected = $step[2];
                TinyAssert::same($expected, $expected === null ? $actual : array_intersect_key((array) $actual, $expected), 'read ' . $step[1]);
            }
        }

        TinyAssert::true($cookie->coreSetCookieBytes() <= 4096, 'cookie within 4096 bytes, was ' . $cookie->coreSetCookieBytes());
        $twoKeys = preg_grep('/^two_(company|mirror)_/', array_keys((new ReflectionProperty($cookie, 'content'))->getValue($cookie)));
        TinyAssert::same([], array_values($twoKeys), 'the cookie must carry no company or mirror data');
    }
}
