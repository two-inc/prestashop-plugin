<?php

declare(strict_types=1);

/**
 * TWO-26082 - the Default shipping tax code fallback is off, and its admin
 * field hidden, until Two enables it for a merchant with the module's console
 * command. Every row runs, and a failure names each row that failed.
 */
final class ShippingTaxFallbackGateSpec
{
    private const ENABLED_KEY = 'PS_TWO_SHIPPING_TAX_FALLBACK_ENABLED';
    private const GROUP_KEY = 'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP';
    private const REFUSAL = 'No deliverable carrier for the cart shipping cost';
    private const COMMAND_FILE = __DIR__ . '/../classes/TwoShippingTaxFallbackCommand.php';

    public static function runAll(): void
    {
        $failures = [];
        foreach (self::checks() as $check) {
            try {
                $check();
            } catch (Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(count($failures) . " row(s) failed:\n  - " . implode("\n  - ", $failures));
        }
    }

    /** @return callable[] */
    private static function checks(): array
    {
        $checks = [];
        foreach (self::resolutionRows() as $row) {
            $checks[] = static function () use ($row): void {
                self::assertResolution(...$row);
            };
        }
        foreach (self::fieldRows() as $row) {
            $checks[] = static function () use ($row): void {
                self::assertFieldVisibility(...$row);
            };
        }
        foreach (self::saveRows() as $row) {
            $checks[] = static function () use ($row): void {
                self::assertSave(...$row);
            };
        }
        foreach (self::commandRows() as $row) {
            $checks[] = static function () use ($row): void {
                self::assertCommand(...$row);
            };
        }
        foreach (self::multishopResolutionRows() as $row) {
            $checks[] = static function () use ($row): void {
                self::assertMultishopResolution(...$row);
            };
        }
        foreach (self::multishopCommandRows() as $row) {
            $checks[] = static function () use ($row): void {
                self::assertMultishopCommand(...$row);
            };
        }

        return $checks;
    }

    // enabled (null = never written), stored group, expect resolved at the group's 21% (else sent at 0%), description
    private static function resolutionRows(): array
    {
        return [
            [null, null, false, 'never enabled + no carrier + no group: sent at 0%'],
            ['0', null, false, 'disabled + no carrier: sent at 0%'],
            [null, '4210', false, 'never enabled with a stored group: ignored, sent at 0%'],
            ['0', '4210', false, 'disabled with a stored group: ignored, sent at 0%'],
            ['1', '4210', true, 'enabled + no carrier + group set: resolved'],
            ['1', null, false, 'enabled + no carrier + no group: sent at 0%'],
        ];
    }

    // enabled, expect field rendered, description
    private static function fieldRows(): array
    {
        return [
            [null, false, 'never enabled: field hidden'],
            ['0', false, 'disabled: field hidden'],
            ['1', true, 'enabled: field rendered'],
        ];
    }

    // enabled, posted group value (null = not posted), stored after, description
    private static function saveRows(): array
    {
        return [
            ['0', null, '4210', 'hidden save omitting the field keeps the stored group'],
            ['0', '', '4210', 'hidden save posting a blank keeps the stored group'],
            ['0', '4100', '4210', 'hidden save posting another group keeps the stored group'],
            ['1', '4100', '4100', 'visible save stores the posted group'],
            ['1', null, '4210', 'visible save omitting the field keeps the stored group'],
            [null, '4100', '4210', 'hidden save on a never-enabled shop keeps the stored group'],
            [null, '', '4210', 'hidden blank save on a never-enabled shop keeps the stored group'],
        ];
    }

    // enabled before, action, expected exit code, enabled after, output fragment, description
    private static function commandRows(): array
    {
        return [
            [null, 'enable', 0, '1', 'enabled', 'command enable from never set'],
            ['0', 'enable', 0, '1', 'enabled', 'command enable from disabled'],
            ['1', 'disable', 0, '0', 'disabled', 'command disable keeps the group'],
            ['1', 'status', 0, '1', 'enabled', 'command status when enabled'],
            [null, 'status', 0, null, 'disabled', 'command status when never set writes nothing'],
            ['1', 'bogus', 1, '1', 'enable|disable|status', 'command rejects an unknown action unchanged'],
        ];
    }

    // Two shops in one group, admin context on shop 1 throughout, so only the cart can point the read at shop 2.
    // global flag, shop 2 flag, cart shop, expect resolved, description
    private static function multishopResolutionRows(): array
    {
        return [
            ['1', null, 2, true, 'multishop, global on: shop 2 cart resolved'],
            ['1', null, 1, true, 'multishop, global on: shop 1 cart resolved'],
            [null, '1', 2, true, 'multishop, shop 2 on only: shop 2 cart resolved'],
            [null, '1', 1, false, 'multishop, shop 2 on only: shop 1 cart sent at 0%'],
            ['0', '1', 2, true, 'multishop, shop 2 on with global off: shop 2 cart resolved'],
            ['0', '1', 1, false, 'multishop, shop 2 on with global off: shop 1 cart sent at 0%'],
            ['1', '0', 2, false, 'multishop, global on with shop 2 off: shop 2 cart sent at 0%'],
        ];
    }

    // global before, shop 2 before, command input, exit code, global after, shop 2 after, output fragments, description
    private static function multishopCommandRows(): array
    {
        return [
            [null, null, ['action' => 'enable'], 0, '1', null, ['shop 1: enabled', 'shop 2: enabled'], 'multishop, enable with no shop writes the global row'],
            [null, null, ['action' => 'enable', '--shop' => '2'], 0, null, '1', ['shop 1: disabled', 'shop 2: enabled'], 'multishop, enable --shop=2 writes only the shop 2 row'],
            ['1', null, ['action' => 'disable', '--shop' => '2'], 0, '1', '0', ['shop 1: enabled', 'shop 2: disabled'], 'multishop, disable --shop=2 leaves global on'],
            ['1', null, ['action' => 'enable', '--shop' => '2'], 0, '1', '1', ['shop 2: enabled (shop setting)'], 'multishop, enable --shop=2 with global on still writes the shop 2 row'],
            ['0', null, ['action' => 'disable', '--shop' => '2'], 0, '0', '0', ['shop 2: disabled (shop setting)'], 'multishop, disable --shop=2 with global off still writes the shop 2 row'],
            ['1', '1', ['action' => 'disable'], 0, '0', '1', ['shop 1: disabled', 'shop 2: enabled (shop setting)'], 'multishop, disable with no shop keeps a shop override'],
            ['0', '1', ['action' => 'status'], 0, '0', '1', ['global: disabled', 'shop 1: disabled (global setting)', 'shop 2: enabled (shop setting)'], 'multishop, status reports each shop'],
            [null, null, ['action' => 'enable', '--id_shop' => '2'], 0, null, '1', ['shop 2: enabled'], 'multishop, core\'s --id_shop=2 on PS 1.7.6-8 writes only the shop 2 row'],
            ['0', null, ['action' => 'enable', '--id_shop' => null], 1, '0', null, ['--id_shop needs a shop id'], 'multishop, a bare --id_shop writes nothing'],
            [null, null, ['action' => 'enable', '--id_shop_group' => '1'], 1, null, null, ['--id_shop_group is not supported'], 'multishop, core\'s --id_shop_group writes nothing'],
            [null, null, ['action' => 'enable', '--shop' => '9'], 1, null, null, ['Unknown shop "9"'], 'multishop, an unknown shop writes nothing'],
            [null, null, ['action' => 'enable', '--shop' => 'two'], 1, null, null, ['Unknown shop "two"'], 'multishop, a non-numeric shop writes nothing'],
        ];
    }

    private static function seed(?string $enabled, ?string $group): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        StubStore::$taxRulesGroups[4100] = ['name' => 'IVA 10%', 'active' => 1];
        StubStore::$taxRuleRates[4210] = 21.0;
        if ($enabled !== null) {
            Configuration::updateValue(self::ENABLED_KEY, $enabled);
        }
        if ($group !== null) {
            Configuration::updateValue(self::GROUP_KEY, $group);
        }
    }

    private static function assertResolution(?string $enabled, ?string $group, bool $resolved, string $description): void
    {
        self::seed($enabled, $group);
        $cart = self::seedCarrierlessCart(9401);
        $module = new TwopaymentTestHarness();

        try {
            $payload = $module->getTwoNewOrderData('merchant-attempt-9401', $cart, self::merchantUrls());
            $outcome = 'resolved at rate ' . self::shippingRate($payload);
        } catch (Exception $e) {
            $outcome = strpos($e->getMessage(), self::REFUSAL) !== false ? 'refused' : 'threw ' . $e->getMessage();
        }

        // With the fallback off or unset the line goes out at 0% with the tax it was charged, never refused (TWO-26117).
        TinyAssert::same($resolved ? 'resolved at rate 0.21' : 'resolved at rate 0', $outcome, $description . ' (got: ' . $outcome . ')');
    }

    private static function assertFieldVisibility(?string $enabled, bool $rendered, string $description): void
    {
        self::seed($enabled, '4210');
        $names = array_map(
            static function (array $input): string {
                return (string) ($input['name'] ?? '');
            },
            (new DefaultShippingTaxCodeHarness())->exposeOrderManagementForm()['form']['input']
        );

        TinyAssert::same($rendered, in_array(self::GROUP_KEY, $names, true), $description);
        TinyAssert::true(in_array('PS_TWO_ENABLE_TAX_SUBTOTALS', $names, true), $description . ': the rest of the tab stays');
    }

    private static function assertSave(?string $enabled, ?string $posted, string $storedAfter, string $description): void
    {
        self::seed($enabled, '4210');
        $module = new DefaultShippingTaxCodeHarness();
        Tools::setTestValue('PS_TWO_ENABLE_TAX_SUBTOTALS', 1);
        if ($posted !== null) {
            Tools::setTestValue(self::GROUP_KEY, $posted);
        }
        TinyAssert::count(0, $module->exposeValidOrderManagementFormValues(), $description . ': no validation error');
        $module->exposeSaveOrderManagementFormValues();

        TinyAssert::same($storedAfter, (string) Configuration::get(self::GROUP_KEY), $description);
    }

    private static function assertCommand(?string $before, string $action, int $exitCode, ?string $after, string $fragment, string $description): void
    {
        self::seed($before, '4210');
        TinyAssert::true(is_file(self::COMMAND_FILE), $description . ': command class missing');
        require_once self::COMMAND_FILE;

        $command = new TwoShippingTaxFallbackCommand();
        $output = new Symfony\Component\Console\Output\BufferedOutput();
        $code = $command->run(new Symfony\Component\Console\Input\ArrayInput(['action' => $action]), $output);

        TinyAssert::same('twopayment:shipping-tax-fallback', $command->getName(), $description . ': command name');
        TinyAssert::same($exitCode, $code, $description . ': exit code');
        $stored = Configuration::get(self::ENABLED_KEY);
        TinyAssert::same($after, $stored === false ? null : (string) $stored, $description . ': stored flag');
        TinyAssert::same('4210', (string) Configuration::get(self::GROUP_KEY), $description . ': the stored group is never touched');
        TinyAssert::true(strpos($output->fetch(), $fragment) !== false, $description . ': output mentions ' . $fragment);
    }

    private static function seedMultishop(?string $global, ?string $shop2): void
    {
        self::seed(null, '4210');
        StubStore::$multistore = true;
        StubStore::$shops = [1 => 1, 2 => 1];
        Shop::setContext(Shop::CONTEXT_SHOP, 1);
        if ($global !== null) {
            StubStore::$configuration[self::ENABLED_KEY] = $global;
        }
        if ($shop2 !== null) {
            StubStore::$configurationShop[2][self::ENABLED_KEY] = $shop2;
        }
    }

    private static function assertMultishopResolution(?string $global, ?string $shop2, int $cartShop, bool $resolved, string $description): void
    {
        self::seedMultishop($global, $shop2);
        $cart = self::seedCarrierlessCart(9402);
        $cart->id_shop = $cartShop;
        $cart->id_shop_group = 1;

        try {
            $payload = (new TwopaymentTestHarness())->getTwoNewOrderData('merchant-attempt-9402', $cart, self::merchantUrls());
            $outcome = 'resolved at rate ' . self::shippingRate($payload);
        } catch (Exception $e) {
            $outcome = strpos($e->getMessage(), self::REFUSAL) !== false ? 'refused' : 'threw ' . $e->getMessage();
        }

        TinyAssert::same($resolved ? 'resolved at rate 0.21' : 'resolved at rate 0', $outcome, $description . ' (got: ' . $outcome . ')');
    }

    private static function assertMultishopCommand(?string $globalBefore, ?string $shop2Before, array $input, int $exitCode, ?string $globalAfter, ?string $shop2After, array $fragments, string $description): void
    {
        self::seedMultishop($globalBefore, $shop2Before);
        require_once self::COMMAND_FILE;

        $output = new Symfony\Component\Console\Output\BufferedOutput();
        $code = (new TwoShippingTaxFallbackCommand())->run(new Symfony\Component\Console\Input\ArrayInput($input), $output);
        $text = $output->fetch();

        TinyAssert::same($exitCode, $code, $description . ': exit code');
        TinyAssert::same($globalAfter, StubStore::$configuration[self::ENABLED_KEY] ?? null, $description . ': global row');
        TinyAssert::same(null, StubStore::$configurationShop[1][self::ENABLED_KEY] ?? null, $description . ': shop 1 row is never written');
        TinyAssert::same($shop2After, StubStore::$configurationShop[2][self::ENABLED_KEY] ?? null, $description . ': shop 2 row');
        foreach ($fragments as $fragment) {
            TinyAssert::true(strpos($text, $fragment) !== false, $description . ': output mentions "' . $fragment . '" (got: ' . trim($text) . ')');
        }
    }

    private static function seedCarrierlessCart(int $id): Cart
    {
        StubStore::$customers[$id] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Pia',
            'lastname' => 'Sol',
            'secure_key' => 'secure-key-' . $id,
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[$id] = [
            'id_country' => 34,
            'company' => 'Shop SL',
            'companyid' => 'E20468708',
            'address1' => 'Calle Uno 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666601',
            'loaded' => true,
        ];
        $cart = new Cart($id);
        $cart->id_customer = $id;
        $cart->id_currency = 978;
        $cart->id_address_invoice = $id;
        $cart->id_address_delivery = $id;
        $cart->id_lang = 1;
        $cart->id_carrier = 0;
        StubStore::$cartProducts[$id] = [[
            'id_product' => $id,
            'link_rewrite' => 'product',
            'name' => 'Product',
            'description_short' => 'Product',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 121.00,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[$id] = [['name' => 'General']];
        StubStore::$images[$id] = ['id_image' => $id];
        StubStore::$products[$id]['id_tax_rules_group'] = 9000 + $id;
        StubStore::$taxRuleRates[9000 + $id] = 21.0;
        StubStore::$cartTotals[$id] = [
            true => [Cart::ONLY_DISCOUNTS => 0.00, Cart::ONLY_SHIPPING => 29.00, Cart::BOTH => 150.00],
            false => [Cart::ONLY_DISCOUNTS => 0.00, Cart::ONLY_SHIPPING => 23.9669, Cart::BOTH => 123.9669],
        ];

        return $cart;
    }

    private static function merchantUrls(): array
    {
        return [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ];
    }

    private static function shippingRate(array $payload): string
    {
        foreach ($payload['line_items'] as $line) {
            if ((string) ($line['type'] ?? '') === 'SHIPPING_FEE') {
                return (string) $line['tax_rate'];
            }
        }

        return '(no shipping line)';
    }
}
