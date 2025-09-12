<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

class CustomerAddressFormatter extends CustomerAddressFormatterCore
{

    private $country;
    private $translator;
    private $availableCountries;
    private $definition;

    public function __construct(Country $country, $translator, array $availableCountries)
    {
        $this->country = $country;
        $this->translator = $translator;
        $this->availableCountries = $availableCountries;
        $this->definition = Address::$definition['fields'];
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

    public function getFormat()
    {
        $fields = AddressFormat::getOrderedAddressFields($this->country->id, true, true);
        $required = array_flip(AddressFormat::getFieldsRequired());
        if (Module::isInstalled('twopayment') && Module::isEnabled('twopayment')) {
            $format = [
                'back' => (new FormField())
                    ->setName('back')
                    ->setType('hidden'),
                'token' => (new FormField())
                    ->setName('token')
                    ->setType('hidden'),
                'alias' => (new FormField())
                    ->setName('alias')
                    ->setLabel(
                        $this->getFieldLabel('alias')
                    ),
            ];

            // Conditionally insert account_type when merchant wants account type selection
            if ((int) Configuration::get('PS_TWO_USE_ACCOUNT_TYPE')) {
                $format = [
                    'back' => (new FormField())
                        ->setName('back')
                        ->setType('hidden'),
                    'token' => (new FormField())
                        ->setName('token')
                        ->setType('hidden'),
                    'account_type' => (new FormField())
                        ->setName('account_type')
                        ->setType('select')
                        ->setRequired(true)
                        ->addAvailableValue('personal', $this->getFieldLabel('personal_type'))
                        ->addAvailableValue('business', $this->getFieldLabel('business_type'))
                        ->setLabel($this->getFieldLabel('account_type')),
                    'alias' => (new FormField())
                        ->setName('alias')
                        ->setLabel(
                            $this->getFieldLabel('alias')
                        ),
                ];
            }

            //insert new fileds - conditionally based on admin settings
            $inserted = array();
            
            // Department field - only add if enabled in admin settings
            if (Configuration::get('PS_TWO_ENABLE_DEPARTMENT')) {
                $inserted[] = 'department';
            }
            
            // Project field - only add if enabled in admin settings  
            if (Configuration::get('PS_TWO_ENABLE_PROJECT')) {
                $inserted[] = 'project';
            }
            
            // Note: companyid field is handled via hidden JavaScript field only
            // No database persistence - form-first approach like old tillit.js
            
            if (!empty($inserted)) {
                array_splice($fields, 3, 0, $inserted);
            }

            //move country fileds
            $out = array_splice($fields, array_search('Country:name', $fields), 1);
            array_splice($fields, 2, 0, $out);

            foreach ($fields as $field) {
                $formField = new FormField();
                $formField->setName($field);
                $fieldParts = explode(':', $field, 2);

                if ($field === 'address2') {
                    $formField->setType('number');
                }

                // CRITICAL: Company field handling for Two payment functionality
                if ($field === 'company') {
                    $formField->addAvailableValue('placeholder', $this->translator->trans('Search your company name', [], 'Shop.Forms.Labels'));
                    if ((int) Configuration::get('PS_TWO_USE_ACCOUNT_TYPE')) {
                        // Make company field conditionally visible and required via JavaScript when using account type
                        $formField->addAvailableValue('data-conditional-field', 'business');
                        $formField->addAvailableValue('data-conditional-required', 'business');
                        // Initially hidden - will be shown when business account is selected
                        $formField->addAvailableValue('data-initial-state', 'hidden');
                    }
                }
                if (count($fieldParts) === 1) {
                    if ($field === 'postcode') {
                        if ($this->country->need_zip_code) {
                            $formField->setRequired(true);
                        }
                    } elseif ($field === 'phone') {
                        $formField->setType('tel');
                        $formField->setRequired(true);
                    } elseif ($field === 'dni' && null !== $this->country) {
                        if ($this->country->need_identification_number) {
                            $formField->setRequired(true);
                        }
                    }
                } elseif (count($fieldParts) === 2) {
                    list($entity, $entityField) = $fieldParts;
                    $formField->setType('select');
                    $formField->setName('id_' . Tools::strtolower($entity));
                    if ($entity === 'Country') {
                        $formField->setType('countrySelect');
                        $formField->setValue($this->country->id);
                        foreach ($this->availableCountries as $country) {
                            $formField->addAvailableValue(
                                $country['id_country'],
                                $country[$entityField]
                            );
                        }
                    } elseif ($entity === 'State') {
                        if ($this->country->contains_states) {
                            $states = State::getStatesByIdCountry($this->country->id, true);
                            foreach ($states as $state) {
                                $formField->addAvailableValue(
                                    $state['id_state'],
                                    $state[$entityField]
                                );
                            }
                            $formField->setRequired(true);
                        }
                    }
                }
                $formField->setLabel($this->getFieldLabel($field));
                if (!$formField->isRequired()) {
                    $formField->setRequired(
                        array_key_exists($field, $required)
                    );
                }
                $format[$formField->getName()] = $formField;
            }
        } else {
            $format = [
                'back' => (new FormField())
                    ->setName('back')
                    ->setType('hidden'),
                'token' => (new FormField())
                    ->setName('token')
                    ->setType('hidden'),
                'alias' => (new FormField())
                    ->setName('alias')
                    ->setLabel(
                        $this->getFieldLabel('alias')
                    ),
            ];
            foreach ($fields as $field) {
                $formField = new FormField();
                $formField->setName($field);
                $fieldParts = explode(':', $field, 2);
                if ($field === 'address2') {
                    $formField->setRequired(true);
                    $formField->setType('number');
                }
                if (count($fieldParts) === 1) {
                    if ($field === 'postcode') {
                        if ($this->country->need_zip_code) {
                            $formField->setRequired(true);
                        }
                    } elseif ($field === 'phone') {
                        $formField->setType('tel');
                    } elseif ($field === 'dni' && null !== $this->country) {
                        if ($this->country->need_identification_number) {
                            $formField->setRequired(true);
                        }
                    }
                } elseif (count($fieldParts) === 2) {
                    list($entity, $entityField) = $fieldParts;
                    $formField->setType('select');
                    $formField->setName('id_' . Tools::strtolower($entity));
                    if ($entity === 'Country') {
                        $formField->setType('countrySelect');
                        $formField->setValue($this->country->id);
                        foreach ($this->availableCountries as $country) {
                            $formField->addAvailableValue(
                                $country['id_country'],
                                $country[$entityField]
                            );
                        }
                    } elseif ($entity === 'State') {
                        if ($this->country->contains_states) {
                            $states = State::getStatesByIdCountry($this->country->id, true);
                            foreach ($states as $state) {
                                $formField->addAvailableValue(
                                    $state['id_state'],
                                    $state[$entityField]
                                );
                            }
                            $formField->setRequired(true);
                        }
                    }
                }
                $formField->setLabel($this->getFieldLabel($field));
                if (!$formField->isRequired()) {
                    $formField->setRequired(
                        array_key_exists($field, $required)
                    );
                }
                $format[$formField->getName()] = $formField;
            }
        }
        $additionalAddressFormFields = Hook::exec('additionalCustomerAddressFields', ['fields' => &$format], null, true);
        if (is_array($additionalAddressFormFields)) {
            foreach ($additionalAddressFormFields as $moduleName => $additionnalFormFields) {
                if (!is_array($additionnalFormFields)) {
                    continue;
                }
                foreach ($additionnalFormFields as $formField) {
                    $formField->moduleName = $moduleName;
                    $format[$moduleName . '_' . $formField->getName()] = $formField;
                }
            }
        }
        return $this->addConstraints($this->addMaxLength($format));
    }

    private function addConstraints(array $format)
    {
        foreach ($format as $field) {
            if (!empty($this->definition[$field->getName()]['validate'])) {
                $field->addConstraint(
                    $this->definition[$field->getName()]['validate']
                );
            }
        }

        return $format;
    }

    private function addMaxLength(array $format)
    {
        foreach ($format as $field) {
            if (!empty($this->definition[$field->getName()]['size'])) {
                $field->setMaxLength(
                    $this->definition[$field->getName()]['size']
                );
            }
        }

        return $format;
    }

    private function getFieldLabel($field)
    {
        // Country:name => Country, Country:iso_code => Country,
        // same label regardless of which field is used for mapping.
        $field = explode(':', $field)[0];

        switch ($field) {
            case 'alias':
                return $this->translator->trans('Alias', [], 'Shop.Forms.Labels');
            case 'firstname':
                return $this->translator->trans('First name', [], 'Shop.Forms.Labels');
            case 'lastname':
                return $this->translator->trans('Last name', [], 'Shop.Forms.Labels');
            case 'address1':
                return $this->translator->trans('Address', [], 'Shop.Forms.Labels');
            case 'address2':
                return $this->translator->trans('Address Complement', [], 'Shop.Forms.Labels');
            case 'postcode':
                return $this->translator->trans('Zip/Postal Code', [], 'Shop.Forms.Labels');
            case 'city':
                return $this->translator->trans('City', [], 'Shop.Forms.Labels');
            case 'Country':
                return $this->translator->trans('Country', [], 'Shop.Forms.Labels');
            case 'State':
                return $this->translator->trans('State', [], 'Shop.Forms.Labels');
            case 'phone':
                return $this->translator->trans('Phone', [], 'Shop.Forms.Labels');
            case 'phone_mobile':
                return $this->translator->trans('Mobile phone', [], 'Shop.Forms.Labels');
            case 'company':
                return $this->translator->trans('Company', [], 'Shop.Forms.Labels');
            case 'vat_number':
                return $this->translator->trans('VAT number', [], 'Shop.Forms.Labels');
            case 'dni':
                return $this->translator->trans('Identification number', [], 'Shop.Forms.Labels');
            case 'other':
                return $this->translator->trans('Other', [], 'Shop.Forms.Labels');
            case 'account_type':
                return $this->translator->trans('Account Type', [], 'Shop.Forms.Labels');
            case 'personal_type':
                return $this->translator->trans('Personal', [], 'Shop.Forms.Labels');
            case 'business_type':
                return $this->translator->trans('Business', [], 'Shop.Forms.Labels');
            case 'companyid':
                return $this->translator->trans('Company ID', [], 'Shop.Forms.Labels');
            case 'department':
                return $this->translator->trans('Department', [], 'Shop.Forms.Labels');
            case 'project':
                return $this->translator->trans('Project', [], 'Shop.Forms.Labels');
            default:
                return $field;
        }
    }
}
