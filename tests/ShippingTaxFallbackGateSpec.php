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

        return $checks;
    }

    // enabled (null = never written), stored group, expect resolved, description
    private static function resolutionRows(): array
    {
        return [
            [null, null, false, 'never enabled + no carrier + no group: refused'],
            ['0', null, false, 'disabled + no carrier: refused'],
            [null, '4210', false, 'never enabled with a stored group: ignored, refused'],
            ['0', '4210', false, 'disabled with a stored group: ignored, refused'],
            ['1', '4210', true, 'enabled + no carrier + group set: resolved'],
            ['1', null, false, 'enabled + no carrier + no group: refused'],
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

        TinyAssert::same($resolved ? 'resolved at rate 0.21' : 'refused', $outcome, $description . ' (got: ' . $outcome . ')');
        if (!$resolved) {
            TinyAssert::same(3, self::severityOf(self::REFUSAL), $description . ': the refusal must stay error-severity');
        }
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

    private static function severityOf(string $needle): int
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos((string) $entry['message'], $needle) !== false) {
                return (int) $entry['severity'];
            }
        }

        return -1;
    }
}
