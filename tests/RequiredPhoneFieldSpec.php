<?php

declare(strict_types=1);

/**
 * The address form's phone field is MANDATORY while this module is enabled
 * (TWO-25326).
 *
 * The module appends `phone` to `AddressFormat::$requireFormFieldsList` from
 * `hookActionFrontControllerInitAfter`. That list is not decoration: core merges
 * it into `AddressFormat::getFieldsRequired()`, `CustomerAddressFormatter`
 * turns every name in the merged list into `FormField::setRequired(true)`, and
 * `AbstractForm::validate()` refuses a required field with an empty value. So a
 * single append is both the rendered asterisk and the server-side refusal.
 *
 * Asserting only "the array now contains phone" would pin the append and prove
 * nothing about the requirement, so the two core consumers are MODELLED below,
 * from the 8.1.x sources (`classes/form/CustomerAddressFormatter.php` lines
 * 66/135-143 and `classes/form/AbstractForm.php` lines 151-160, both also
 * checked on `develop`/PS9). The model is deliberately thin - it exists to make
 * the consequence of the append observable, not to re-test core.
 *
 * The DB `required_field` table is the other way this could have been spelled
 * and is asserted NOT to be used, because writing through
 * `ObjectModel::addFieldsRequiredDatabase()` deletes the merchant's own
 * selections and makes every programmatic address save fail on an empty phone.
 */
final class RequiredPhoneFieldSpec
{
    public static function runAll(): void
    {
        self::testHookMakesPhoneRequired();
        self::testHookIsIdempotent();
        self::testMerchantConfiguredRequiredFieldsSurvive();
        self::testCoreFormatterMarksThePhoneFieldRequired();
        self::testAnEmptyPhoneFailsValidation();
        self::testAFilledPhonePassesValidation();
        self::testTheRequiredFieldTableIsNeverWritten();
        self::testTheHookIsRegisteredOnInstall();
        self::testTheOverrideAlsoRequiresPhone();
    }

    /**
     * Core's default seed, restored around every case so ordering between specs
     * cannot make any of them vacuous.
     *
     * @var string[]
     */
    private const CORE_DEFAULTS = array('firstname', 'lastname', 'address1', 'city', 'Country:name');

    private static function reset(): void
    {
        StubStore::reset();
        AddressFormat::$requireFormFieldsList = self::CORE_DEFAULTS;
        AddressFormat::$fieldsRequiredDatabase = array();
        AddressFormat::$addFieldsRequiredDatabaseCalls = 0;
    }

    private static function fireHook(): void
    {
        $module = new TwopaymentTestHarness();
        $module->hookActionFrontControllerInitAfter(array());
    }

    private static function testHookMakesPhoneRequired(): void
    {
        self::reset();
        TinyAssert::false(
            in_array('phone', AddressFormat::$requireFormFieldsList, true),
            'Core does not require phone by default; if it did, this whole change would be a no-op.'
        );

        self::fireHook();

        TinyAssert::true(
            in_array('phone', AddressFormat::$requireFormFieldsList, true),
            'phone must be in the required-form-fields list once the module has initialised.'
        );
    }

    /**
     * The hook fires on EVERY front request, and the property is static for the
     * whole PHP process - a second append would leave a duplicate that
     * `getFieldsRequired()`'s array_unique() hides here but the back office's
     * required-fields screen would not.
     */
    private static function testHookIsIdempotent(): void
    {
        self::reset();
        self::fireHook();
        self::fireHook();
        self::fireHook();

        $phoneEntries = 0;
        foreach (AddressFormat::$requireFormFieldsList as $field) {
            if ($field === 'phone') {
                ++$phoneEntries;
            }
        }
        TinyAssert::same(1, $phoneEntries, 'phone must be appended exactly once however often the hook fires.');
    }

    /**
     * The merchant's Customers > Addresses selections live in the
     * `required_field` table and are merged with the static list, not replaced
     * by it. Pinned because the alternative implementation (writing the table)
     * would have destroyed them.
     */
    private static function testMerchantConfiguredRequiredFieldsSurvive(): void
    {
        self::reset();
        AddressFormat::$fieldsRequiredDatabase = array('vat_number', 'dni');

        self::fireHook();

        $required = AddressFormat::getFieldsRequired();
        foreach (array('vat_number', 'dni', 'phone', 'firstname', 'city') as $field) {
            TinyAssert::true(
                in_array($field, $required, true),
                'Expected ' . $field . ' among the required fields, got: ' . implode(', ', $required)
            );
        }
    }

    private static function testCoreFormatterMarksThePhoneFieldRequired(): void
    {
        self::reset();

        $before = self::coreFormat();
        TinyAssert::false($before['phone']->isRequired(), 'Baseline: core leaves phone optional.');

        self::fireHook();

        $after = self::coreFormat();
        TinyAssert::true($after['phone']->isRequired(), 'The formatter must mark phone required after the hook ran.');
        // The append must not spill onto anything else the formatter builds.
        TinyAssert::false($after['address2']->isRequired(), 'address2 must stay optional.');
        TinyAssert::true($after['city']->isRequired(), 'city was already required by core and must stay so.');
    }

    private static function testAnEmptyPhoneFailsValidation(): void
    {
        self::reset();
        self::fireHook();

        $format = self::fillEveryFieldExceptPhone(self::coreFormat());
        $format['phone']->setValue('');

        TinyAssert::false(self::coreValidate($format), 'An address with no phone must not validate.');
        TinyAssert::same(
            array('required'),
            $format['phone']->getErrors(),
            'The refusal must be attached to the phone field, so the buyer is told which field it is.'
        );
    }

    private static function testAFilledPhonePassesValidation(): void
    {
        self::reset();
        self::fireHook();

        $format = self::fillEveryFieldExceptPhone(self::coreFormat());
        $format['phone']->setValue('+47 21 08 08 08');

        TinyAssert::true(self::coreValidate($format), 'A filled phone must validate.');
        TinyAssert::count(0, $format['phone']->getErrors());
    }

    /**
     * `ObjectModel::addFieldsRequiredDatabase()` DELETEs every row for the
     * object before inserting, and a field required there is enforced on every
     * programmatic save. Neither is wanted, so the module must never call it.
     */
    private static function testTheRequiredFieldTableIsNeverWritten(): void
    {
        self::reset();
        self::fireHook();

        TinyAssert::same(
            0,
            AddressFormat::$addFieldsRequiredDatabaseCalls,
            'The module must not write the required_field table.'
        );

        // `ObjectModel::addFieldsRequiredDatabase()` is an INSTANCE method, so a
        // real call site can only be `->addFieldsRequiredDatabase(`. Matching
        // that shape rather than the bare name keeps the prose above (which
        // names it, deliberately, to record why it is not used) out of the way.
        $source = (string) file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::false(
            strpos($source, '->addFieldsRequiredDatabase(') !== false,
            'twopayment.php must not call addFieldsRequiredDatabase().'
        );
    }

    /**
     * The append is worth nothing if the hook it lives in is not registered:
     * install() is the only thing that registers it, and an unregistered hook
     * is silent.
     */
    private static function testTheHookIsRegisteredOnInstall(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::true(
            strpos($source, "registerHook('actionFrontControllerInitAfter')") !== false,
            'install() must register actionFrontControllerInitAfter.'
        );
        TinyAssert::true(
            strpos($source, '$this->requirePhoneOnAddressForms();') !== false,
            'hookActionFrontControllerInitAfter must call requirePhoneOnAddressForms().'
        );
    }

    /**
     * The module's own CustomerAddressFormatter override says the same thing a
     * second time. Kept, and pinned, because on a shop whose override copy is
     * current the two agree, and because removing it would be a behaviour change
     * this ticket did not ask for.
     */
    private static function testTheOverrideAlsoRequiresPhone(): void
    {
        $override = (string) file_get_contents(dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php');
        TinyAssert::true(
            strpos($override, "\$format['phone']->setRequired(true);") !== false,
            'The override must still mark phone required.'
        );
    }

    /**
     * Model of CustomerAddressFormatter::getFormat()'s required-field handling
     * (8.1.x lines 66 and 135-143): build the country's fields, then trust the
     * merged required list for anything not already required for another reason.
     *
     * @return array<string, FormField>
     */
    private static function coreFormat(): array
    {
        // PrestaShop's default address format, phone included - it is in the
        // shipped format for every country in install-dev/data/xml.
        $fields = array('firstname', 'lastname', 'company', 'vat_number', 'address1', 'address2', 'postcode', 'city', 'Country:name', 'phone');
        $required = array_flip(AddressFormat::getFieldsRequired());

        $format = array();
        foreach ($fields as $field) {
            $formField = new FormField();
            $formField->setName($field);
            if ($field === 'phone') {
                $formField->setType('tel');
            }
            if (!$formField->isRequired()) {
                $formField->setRequired(array_key_exists($field, $required));
            }
            $format[$field] = $formField;
        }

        return $format;
    }

    /**
     * Everything else a buyer would have filled in, so the two validation cases
     * differ in the phone field ALONE - otherwise "invalid" would be true for
     * the wrong reason and the empty-phone case would pass vacuously.
     *
     * @param array<string, FormField> $format
     * @return array<string, FormField>
     */
    private static function fillEveryFieldExceptPhone(array $format): array
    {
        foreach ($format as $name => $field) {
            if ($name !== 'phone') {
                $field->setValue('filled');
            }
        }

        return $format;
    }

    /**
     * Model of AbstractForm::validate()'s required-field loop (8.1.x lines
     * 151-160). Length checks are out of scope here.
     *
     * @param array<string, FormField> $format
     */
    private static function coreValidate(array $format): bool
    {
        $isValid = true;
        foreach ($format as $field) {
            if ($field->isRequired() && !$field->getValue()) {
                $field->addError('required');
                $isValid = false;
            }
        }

        return $isValid;
    }
}
