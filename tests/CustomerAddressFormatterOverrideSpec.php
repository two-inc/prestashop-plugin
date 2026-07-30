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
            $isSpanishCountry = isset($this->country->id) && (int) $this->country->id === 6;

            return [
                'alias' => (new FormField())
                    ->setName('alias')
                    ->setType('text')
                    ->setLabel($this->getFieldLabel('alias')),
                'company' => (new FormField())->setName('company')->setType('text'),
                'id_country' => (new FormField())->setName('id_country')->setType('countrySelect'),
                'phone' => (new FormField())->setName('phone')->setType('text'),
                'dni' => (new FormField())->setName('dni')->setType('text')->setRequired($isSpanishCountry),
            ];
        }

        public function setCountry(Country $country)
        {
            $this->country = $country;

            return $this;
        }

        public function getCountry()
        {
            return $this->country;
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
        self::testDniFieldIsPreservedByOverride();
        self::testCountrySwitchKeepsCoreFormatterCountryInSync();
        self::testCompanyPlaceholderIsTheEmptyFieldHint();
    }

    /**
     * The empty-field hint (TWO-25288) as a standard shop actually renders it.
     *
     * The browser JS applies the same wording when the slot is empty, and the JS
     * suite covers that - but on a shop holding this override the placeholder is
     * already in the markup, so the override is the path that ships the hint. An
     * assertion on the JS half alone would leave the shipped half unpinned.
     */
    private static function testCompanyPlaceholderIsTheEmptyFieldHint(): void
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

        TinyAssert::true(isset($format['company']) && $format['company'] instanceof FormField, 'Expected company field in formatter output');

        $availableValues = $format['company']->getAvailableValues();

        TinyAssert::true(array_key_exists('placeholder', $availableValues), 'Expected company field to carry a placeholder');
        TinyAssert::same(
            'Enter company name to search',
            $availableValues['placeholder'],
            'Expected company placeholder to be the empty-field search hint'
        );
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

    private static function testDniFieldIsPreservedByOverride(): void
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

        $formatter = new CustomerAddressFormatter(self::makeCountry(6), $translator, []);
        $format = $formatter->getFormat();

        TinyAssert::true(isset($format['dni']) && $format['dni'] instanceof FormField, 'Expected dni field in formatter output');
        TinyAssert::true($format['dni']->isRequired(), 'Expected dni field required flag to be preserved');
    }

    private static function testCountrySwitchKeepsCoreFormatterCountryInSync(): void
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

        $ukCountry = self::makeCountry(17);
        $esCountry = self::makeCountry(6);

        $formatter = new CustomerAddressFormatter($ukCountry, $translator, []);
        $formatter->setCountry($esCountry);
        $format = $formatter->getFormat();

        TinyAssert::same(6, (int) $formatter->getCountry()->id, 'Expected formatter country to switch to Spain');
        TinyAssert::true(isset($format['dni']) && $format['dni']->isRequired(), 'Expected dni to be required after country switch to Spain');
    }

    private static function makeCountry(int $id): Country
    {
        return new class($id) extends Country {
            public $id;

            public function __construct(int $id)
            {
                $this->id = $id;
            }
        };
    }
}
