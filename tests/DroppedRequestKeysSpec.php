<?php

declare(strict_types=1);

/**
 * ABN-621: request keys Two's API does not define on a request type are no longer sent.
 *
 *  - The order edit carries no order-level `buyer`.
 *  - The order intent carries no order-level `discount_amount`, `billing_address` or `tax_subtotals`.
 *  - The order create is unchanged, and so are the line items' own `discount_amount`.
 *
 * Two hashes used to read the dropped keys. Neither may go blind with them:
 *
 *  - The edit's change detection read `buyer.company`. It now reads the buyer company the order's own data
 *    resolves, so a company change still sends an edit, and an unchanged order keeps the hash it had (no edit is
 *    re-sent at deploy).
 *  - The intent decision key read `billing_address.country` and `discount_amount` (the checkout snapshot hash). The
 *    cart supplies both now: the proofs are in CheckoutLatencySpec, where the key's other inputs are.
 */
final class DroppedRequestKeysSpec
{
    /**
     * getTwoUpdateHashBasis() of the golden order, as md5(json_encode(basis)), taken before ABN-621 when the basis
     * read the company from the body's buyer block. Keyed by whether the order row stores its company.
     */
    private const PRE_ABN_621_BASIS_MD5 = [
        'company not stored on the order' => 'bfb68f4abd38e34dbeff56b13d570735',
        'company stored on the order' => '84f00525976f3bff98332f7c69e150c6',
    ];

    public static function runAll(): void
    {
        self::testKeysAreDroppedOnlyFromTheRequestsTheApiDoesNotDefineThemOn();
        self::testACompanyChangeStillChangesTheEditHashBasis();
        self::testAnUnchangedOrderKeepsItsEditHash();
        self::testACompanyChangeSendsAnEditAndNoChangeSendsNone();
    }

    /**
     * @return array<string,array>
     */
    private static function payloads(): array
    {
        $cart = OrderPostprocessingSpec::seed(true);
        $module = new TwopaymentTestHarness();
        OrderPostprocessingSpec::harnessAsInstance($module);
        $decoded = [];
        foreach (OrderPostprocessingSpec::payloadsForGolden($module, $cart) as $name => $json) {
            $decoded[$name] = json_decode($json, true);
        }

        return $decoded;
    }

    /**
     * One row per request type and key: is the key in the body the module sends. The create row proves a shared
     * builder drops a key only for the call it was dropped from.
     */
    private static function testKeysAreDroppedOnlyFromTheRequestsTheApiDoesNotDefineThemOn(): void
    {
        $payloads = self::payloads();
        // [request type, key path, in the body, description]
        $cases = [
            ['update', 'buyer', false, 'edit: order-level buyer is dropped'],
            ['update', 'merchant_urls', false, 'edit: order-level merchant_urls is not sent'],
            ['update', 'vendor_name', false, 'edit: order-level vendor_name is not sent'],
            ['update', 'billing_address', true, 'edit: billing_address stays'],
            ['update', 'discount_amount', true, 'edit: order-level discount_amount stays'],
            ['update', 'line_items.0.discount_amount', true, 'edit: a line item\'s discount_amount stays'],
            ['intent', 'discount_amount', false, 'intent: order-level discount_amount is dropped'],
            ['intent', 'billing_address', false, 'intent: billing_address is dropped'],
            ['intent', 'tax_subtotals', false, 'intent: tax_subtotals is dropped'],
            ['intent', 'buyer', true, 'intent: buyer stays'],
            ['intent', 'shipping_address', true, 'intent: shipping_address stays'],
            ['intent', 'line_items.0.discount_amount', true, 'intent: a line item\'s discount_amount stays'],
            ['strict_intent', 'discount_amount', false, 'strict intent: order-level discount_amount is dropped'],
            ['strict_intent', 'billing_address', false, 'strict intent: billing_address is dropped'],
            ['strict_intent', 'tax_subtotals', false, 'strict intent: tax_subtotals is dropped'],
            ['strict_intent', 'buyer', true, 'strict intent: buyer stays'],
            ['strict_intent', 'line_items.0.discount_amount', true, 'strict intent: a line item\'s discount_amount stays'],
            ['create', 'buyer', true, 'create: buyer is unchanged'],
            ['create', 'merchant_urls', true, 'create: merchant_urls is unchanged'],
            ['create', 'billing_address', true, 'create: billing_address is unchanged'],
            ['create', 'discount_amount', true, 'create: order-level discount_amount is unchanged'],
            ['create', 'tax_subtotals', true, 'create: tax_subtotals is unchanged'],
            ['create', 'line_items.0.discount_amount', true, 'create: a line item\'s discount_amount is unchanged'],
        ];
        foreach ($cases as [$request, $path, $present, $description]) {
            TinyAssert::true(isset($payloads[$request]), $description . ': the request is one of the payloads under test');
            $node = $payloads[$request];
            foreach (explode('.', $path) as $key) {
                $found = is_array($node) && array_key_exists($key, $node);
                $node = $found ? $node[$key] : null;
                if (!$found) {
                    break;
                }
            }
            TinyAssert::same($present, $found, $description . ' (' . $path . ')');
        }
    }

    /**
     * The hash basis of one edit of the golden order, built the way putTwoOrderUpdate() builds it.
     *
     * @return array{0:string,1:string} the md5 of the basis and the JSON of the body
     */
    private static function editHash(array $row): array
    {
        $module = new TwopaymentTestHarness();
        OrderPostprocessingSpec::harnessAsInstance($module);
        $order = OrderPostprocessingSpec::order();
        $basis = new ReflectionMethod(Twopayment::class, 'getTwoUpdateHashBasis');
        $body = $module->getTwoUpdateOrderData($order, $row);

        return [
            md5(json_encode($basis->invoke($module, $body, $module->getTwoUpdateBuyerCompany($order, $row)))),
            (string) json_encode($body),
        ];
    }

    private static function row(array $stored = []): array
    {
        return ['two_order_id' => 'two-order-9731', 'two_order_reference' => 'ref', 'two_day_on_invoice' => '30'] + $stored;
    }

    /**
     * A buyer-company change must still send an edit. Where the body cannot show the change it is asserted
     * identical, so the hash can differ only because it reads the order's own company.
     */
    private static function testACompanyChangeStillChangesTheEditHashBasis(): void
    {
        OrderPostprocessingSpec::seed(true);
        $base = ['two_organization_number' => 'B12345678', 'two_company_name' => 'Hook Shop SL'];
        // [row, row it is compared with, whether the edit hash differs, whether the body is the same, description]
        $cases = [
            [self::row($base), self::row($base), false, true, 'the same company: no edit'],
            // The body has no buyer block and carries no organisation number elsewhere, so it cannot show this change.
            [self::row($base), self::row(['two_organization_number' => 'B87654321'] + $base), true, true, 'another organisation number'],
            // The name reaches the body through billing_address.organization_name, so it is not a pure case.
            [self::row($base), self::row(['two_company_name' => 'Hook Shop SL (Madrid)'] + $base), true, false, 'another company name'],
        ];
        foreach ($cases as [$row, $other, $differs, $sameBody, $description]) {
            [$hash, $body] = self::editHash($row);
            [$otherHash, $otherBody] = self::editHash($other);
            TinyAssert::same($sameBody, $body === $otherBody, $description . ': the body ' . ($sameBody ? 'must be the same, so the hash alone sees the change' : 'differs'));
            TinyAssert::same($differs, $hash !== $otherHash, $description . ': edit hash ' . ($differs ? 'must differ' : 'must match'));
        }
    }

    /**
     * The same through putTwoOrderUpdate(): with the hash of the last accepted edit stored, an unchanged order sends
     * no PUT, and a changed organisation number (which the body cannot show) sends one.
     */
    private static function testACompanyChangeSendsAnEditAndNoChangeSendsNone(): void
    {
        OrderPostprocessingSpec::seed(true);
        $base = ['two_organization_number' => 'B12345678', 'two_company_name' => 'Hook Shop SL'];
        $accepted = ['two_update_hash' => self::editHash(self::row($base))[0]];
        // [stored company of the order, PUTs expected, description]
        $cases = [
            [$base, 0, 'no change: no edit sent'],
            [['two_organization_number' => 'B87654321'] + $base, 1, 'another organisation number: an edit is sent'],
        ];
        foreach ($cases as [$stored, $puts, $description]) {
            $module = OrderPostprocessingSpec::module();
            OrderPostprocessingSpec::harnessAsInstance($module);
            $payload = null;
            $module->putTwoOrderUpdate(OrderPostprocessingSpec::order(), self::row($stored) + $accepted, $payload, 'merchant_order_id');
            $sent = array_filter($module->sent, static function (array $request): bool {
                return $request['method'] === 'PUT';
            });
            TinyAssert::count($puts, array_values($sent), $description);
        }
    }

    /**
     * An order's stored hash is the md5 of the basis. Taking the company from the order's own data instead of the
     * body must not change the hash of an order that did not change, or every order would send an edit at deploy.
     */
    private static function testAnUnchangedOrderKeepsItsEditHash(): void
    {
        OrderPostprocessingSpec::seed(true);
        $cases = [
            ['company not stored on the order', self::row()],
            ['company stored on the order', self::row(['two_organization_number' => 'X-1', 'two_company_name' => 'Stored Co'])],
        ];
        foreach ($cases as [$description, $row]) {
            TinyAssert::same(self::PRE_ABN_621_BASIS_MD5[$description], self::editHash($row)[0], $description . ': the edit hash is the one taken before ABN-621');
        }
    }
}
