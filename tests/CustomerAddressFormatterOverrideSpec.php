<?php

declare(strict_types=1);

if (!class_exists('CustomerAddressFormatterCore', false)) {
    class CustomerAddressFormatterCore
    {
        private $country;
        private $translator;
        private $availableCountries;

        public function __construct(Country $country, $translator, array $availableCountries)
        {
            $this->country = $country;
            $this->translator = $translator;
            $this->availableCountries = $availableCountries;
        }

        public function getFormat()
        {
            return [
                'alias' => (new FormField())
                    ->setName('alias')
                    ->setType('text')
                    ->setLabel($this->getFieldLabel('alias')),
                'company' => (new FormField())->setName('company')->setType('text'),
                'id_country' => (new FormField())->setName('id_country')->setType('countrySelect'),
                'phone' => (new FormField())->setName('phone')->setType('text'),
            ];
        }

        private function getFieldLabel(string $field): string
        {
            return $this->translator->trans('Alias', [], 'Shop.Forms.Labels');
        }
    }
}

final class CustomerAddressFormatterOverrideSpec
{
    public static function runAll(): void
    {
        self::testOverrideConstructorInitializesCoreTranslatorState();
        self::testCountryFieldIsPositionedBeforeCompany();
    }

    private static function testOverrideConstructorInitializesCoreTranslatorState(): void
    {
        $overridePath = dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php';
        if (!class_exists('CustomerAddressFormatter', false)) {
            require_once $overridePath;
        }

        $translator = new class {
            public function trans($message, array $params = [], $domain = null): string
            {
                return (string) $message;
            }
        };

        $formatter = new CustomerAddressFormatter(new Country(), $translator, []);

        try {
            $format = $formatter->getFormat();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'CustomerAddressFormatter override must keep core formatter translator initialized. Failure: '
                . $exception->getMessage()
            );
        }

        TinyAssert::true(isset($format['alias']) && $format['alias'] instanceof FormField, 'Expected alias field in formatter output');
        TinyAssert::same('Alias', $format['alias']->getLabel(), 'Expected alias label translation from core formatter');
    }

    private static function testCountryFieldIsPositionedBeforeCompany(): void
    {
        $overridePath = dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php';
        if (!class_exists('CustomerAddressFormatter', false)) {
            require_once $overridePath;
        }

        $translator = new class {
            public function trans($message, array $params = [], $domain = null): string
            {
                return (string) $message;
            }
        };

        $formatter = new CustomerAddressFormatter(new Country(), $translator, []);
        $format = $formatter->getFormat();
        $keys = array_keys($format);
        $countryPosition = array_search('id_country', $keys, true);
        $companyPosition = array_search('company', $keys, true);

        TinyAssert::true($countryPosition !== false, 'Expected id_country field in formatter output');
        TinyAssert::true($companyPosition !== false, 'Expected company field in formatter output');
        TinyAssert::true(
            $countryPosition < $companyPosition,
            'Expected country selector to be positioned before company field in checkout addresses'
        );
    }
}
