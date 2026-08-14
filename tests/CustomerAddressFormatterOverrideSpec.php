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
        self::testCompanyPlaceholderIsWithheldWhenTheApiKeyIsRejected();
        self::testEveryKnownFailureWithholdsThePlaceholder();
        self::testCompanyPlaceholderSurvivesAnUnconfirmedVerdict();
        self::testCompanyPlaceholderSurvivesAnUnreachableModuleInstance();
        self::testCompanyPlaceholderSurvivesAThrowingModuleInstance();
        self::testCompanyPlaceholderSurvivesAModuleThatCannotAnswerAtAll();
        self::testTheOverrideNeverGoesToTheNetwork();
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


    /**
     * TWO-25326: the hint tells the buyer to search, and nothing will search
     * when the shop's API key has been REJECTED - the module mounts no search
     * control in that state and the field is a plain text input. Withheld
     * server-side here as well as stripped in the browser: this is the half that
     * survives a back-office translation of the core string, which the browser
     * cannot recognise as the module's own wording.
     */
    private static function testCompanyPlaceholderIsWithheldWhenTheApiKeyIsRejected(): void
    {
        $format = self::formatWithModuleVerdict(Twopayment::API_KEY_STATUS_INVALID);

        TinyAssert::true(isset($format['company']), 'the company field itself must still be there');
        TinyAssert::false(
            array_key_exists('placeholder', $format['company']->getAvailableValues()),
            'no search hint while nothing will search'
        );
    }

    /**
     * Every KNOWN failure withholds it, not only a rejected key (review round 4).
     * The browser-side half of this affordance stands down on any non-ok verdict,
     * and the two halves acting on one field under different policies is a
     * disagreement a merchant can see: on a shop with a back-office translation
     * of the core placeholder string - the exact case the server-side half exists
     * for - the hint survived on a field with no search behind it.
     */
    private static function testEveryKnownFailureWithholdsThePlaceholder(): void
    {
        foreach (
            [
                Twopayment::API_KEY_STATUS_SERVICE_ERROR,
                Twopayment::API_KEY_STATUS_UNREACHABLE,
                Twopayment::API_KEY_STATUS_ERROR,
                Twopayment::API_KEY_STATUS_NOT_CONFIGURED,
            ] as $status
        ) {
            $format = self::formatWithModuleVerdict($status);

            TinyAssert::false(
                array_key_exists('placeholder', $format['company']->getAvailableValues()),
                'verdict "' . $status . '" must withhold the hint, as the JS gate does'
            );
        }
    }

    /**
     * ...but an as-yet-UNKNOWN verdict does not. This runs inside address-form
     * rendering - on my-account pages as well as checkout - so it reads the
     * verdict cache-only and never makes the verification call itself; a cold
     * cache is not evidence of a broken shop, and flickering the hint away on
     * one would be a worse outcome than leaving it.
     */
    private static function testCompanyPlaceholderSurvivesAnUnconfirmedVerdict(): void
    {
        $format = self::formatWithModuleVerdict(Twopayment::API_KEY_STATUS_VERIFYING);

        TinyAssert::true(
            array_key_exists('placeholder', $format['company']->getAvailableValues()),
            'an unconfirmed verdict must not cost the hint'
        );
    }

    /**
     * ...and fail-OPEN when the module instance cannot be reached at all: an
     * override that cannot ask must render what it always rendered, never strip
     * a hint from a shop that is fine.
     */
    private static function testCompanyPlaceholderSurvivesAnUnreachableModuleInstance(): void
    {
        $format = self::formatWithModuleVerdict(null);

        TinyAssert::true(
            array_key_exists('placeholder', $format['company']->getAvailableValues()),
            'an unreachable module instance must not cost the hint'
        );
    }


    /**
     * ...and survives an instance that THROWS, not merely one that is absent
     * (review round 3 survivor). A TypeError or any other Error out of the
     * module - a fatal in its construction, a missing class on an odd install -
     * would otherwise escape into address-form rendering and break the address
     * step outright, on every page that renders one. Catching Exception alone
     * does not cover that, and nothing else in the suite noticed the difference.
     */
    private static function testCompanyPlaceholderSurvivesAThrowingModuleInstance(): void
    {
        $overridePath = dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php';
        if (!class_exists('CustomerAddressFormatter', false)) {
            require_once $overridePath;
        }

        StubStore::$moduleInstances['twopayment'] = new class extends TwopaymentTestHarness {
            // The method the override ACTUALLY calls. Round 4 renamed the
            // question and this stub kept throwing from the old one, so it threw
            // nothing and the test passed down the cold-cache path instead -
            // proven when `catch (Throwable)` could be narrowed to
            // `catch (Exception)` with the whole suite still green (round 6).
            public function isTwoCompanySearchAffordanceWarranted($allowLiveCheck = false)
            {
                throw new TypeError('module blew up while answering');
            }
        };

        $translator = new class {
            public function trans($message, array $params = [], $domain = null): string
            {
                return (string) $message;
            }
        };

        $formatter = new CustomerAddressFormatter(new Country(), $translator, []);
        $format = $formatter->getFormat();
        StubStore::$moduleInstances = [];

        TinyAssert::true(
            array_key_exists('placeholder', $format['company']->getAvailableValues()),
            'a throwing module instance must not cost the hint - or the address form'
        );
    }


    /**
     * An instance that does not answer this question at all - a shop mid-upgrade,
     * where the class on disk is older than the override copied into the shop
     * tree (which is exactly the staleness TwoOverrideMigrator exists for). The
     * guard that covers it was unpinned (round 6).
     */
    private static function testCompanyPlaceholderSurvivesAModuleThatCannotAnswerAtAll(): void
    {
        $overridePath = dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php';
        if (!class_exists('CustomerAddressFormatter', false)) {
            require_once $overridePath;
        }

        // Deliberately NOT a Twopayment: an object with none of the module's
        // methods stands in for a version that predates this one.
        StubStore::$moduleInstances['twopayment'] = new class {
        };

        $translator = new class {
            public function trans($message, array $params = [], $domain = null): string
            {
                return (string) $message;
            }
        };

        $formatter = new CustomerAddressFormatter(new Country(), $translator, []);
        $format = $formatter->getFormat();
        StubStore::$moduleInstances = [];

        TinyAssert::true(
            array_key_exists('placeholder', $format['company']->getAvailableValues()),
            'a module that cannot answer must not cost the hint'
        );
    }

    /**
     * The override renders inside address forms - on my-account pages as well as
     * checkout - so it must never be the thing that makes the verification call.
     * The cache-only default on isTwoCompanySearchAffordanceWarranted() is the
     * whole mechanism, and flipping that default left every suite green (round 6).
     */
    private static function testTheOverrideNeverGoesToTheNetwork(): void
    {
        $overridePath = dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php';
        if (!class_exists('CustomerAddressFormatter', false)) {
            require_once $overridePath;
        }

        StubStore::reset();
        Tools::resetTestValues();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'stored-key');
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging');

        // A COLD cache: nothing stored, no memo. Anything that consults the
        // network would do it here.
        $module = new class extends TwopaymentTestHarness {
            public int $wireCalls = 0;

            public function __construct()
            {
                parent::__construct();
                $this->primeTwoApiKeyStatus(null);
            }

            protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
            {
                $this->wireCalls++;
                return array('response' => json_encode(array('id' => 'm', 'short_name' => 's')), 'code' => 200, 'error' => '');
            }
        };
        StubStore::$moduleInstances['twopayment'] = $module;

        $translator = new class {
            public function trans($message, array $params = [], $domain = null): string
            {
                return (string) $message;
            }
        };

        $formatter = new CustomerAddressFormatter(new Country(), $translator, []);
        $format = $formatter->getFormat();
        StubStore::$moduleInstances = [];

        TinyAssert::same(0, $module->wireCalls, 'an address-form render must not make the verification call');
        // And an unresolved verdict leaves the form as it was.
        TinyAssert::true(
            array_key_exists('placeholder', $format['company']->getAvailableValues()),
            'a cold cache is not evidence of a broken shop'
        );
    }

    /**
     * @param string|null $status null registers no module instance at all
     *
     * @return array<string,FormField>
     */
    private static function formatWithModuleVerdict($status): array
    {
        $overridePath = dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php';
        if (!class_exists('CustomerAddressFormatter', false)) {
            require_once $overridePath;
        }

        StubStore::$moduleInstances = [];
        if ($status !== null) {
            $module = new TwopaymentTestHarness();
            $module->primeTwoApiKeyStatus($status);
            StubStore::$moduleInstances['twopayment'] = $module;
        }

        $translator = new class {
            public function trans($message, array $params = [], $domain = null): string
            {
                return (string) $message;
            }
        };

        $formatter = new CustomerAddressFormatter(new Country(), $translator, []);
        $format = $formatter->getFormat();
        StubStore::$moduleInstances = [];

        return $format;
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
