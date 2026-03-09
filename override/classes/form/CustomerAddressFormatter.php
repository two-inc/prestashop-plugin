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
        parent::__construct($country, $translator, $availableCountries);
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
        $format = parent::getFormat();
        if (!is_array($format) || !Module::isInstalled('twopayment') || !Module::isEnabled('twopayment')) {
            return $format;
        }

        $format = $this->moveFieldBefore($format, 'id_country', 'company');

        $useAccountType = (int) Configuration::get('PS_TWO_USE_ACCOUNT_TYPE') === 1;

        if ($useAccountType && !isset($format['account_type'])) {
            $accountTypeField = (new FormField())
                ->setName('account_type')
                ->setType('select')
                ->setRequired(true)
                ->addAvailableValue('personal', $this->getFieldLabel('personal_type'))
                ->addAvailableValue('business', $this->getFieldLabel('business_type'))
                ->setLabel($this->getFieldLabel('account_type'));
            $this->applyFieldDefinitionMetadata($accountTypeField, 'account_type');
            $format = $this->insertFieldAfter($format, 'token', 'account_type', $accountTypeField);
        }

        if (isset($format['company']) && $format['company'] instanceof FormField) {
            $format['company']->addAvailableValue('placeholder', $this->translator->trans('Search your company name', [], 'Shop.Forms.Labels'));
            if ($useAccountType) {
                $format['company']->addAvailableValue('data-conditional-field', 'business');
                $format['company']->addAvailableValue('data-conditional-required', 'business');
                $format['company']->addAvailableValue('data-initial-state', 'hidden');
            }
        }

        if (isset($format['phone']) && $format['phone'] instanceof FormField) {
            $format['phone']->setType('tel');
            $format['phone']->setRequired(true);
        }

        if ((int) Configuration::get('PS_TWO_ENABLE_DEPARTMENT') === 1 && !isset($format['department'])) {
            $departmentField = (new FormField())
                ->setName('department')
                ->setType('text')
                ->setLabel($this->getFieldLabel('department'));
            $this->applyFieldDefinitionMetadata($departmentField, 'department');
            $format = $this->insertFieldAfter($format, 'company', 'department', $departmentField);
        }

        if ((int) Configuration::get('PS_TWO_ENABLE_PROJECT') === 1 && !isset($format['project'])) {
            $projectField = (new FormField())
                ->setName('project')
                ->setType('text')
                ->setLabel($this->getFieldLabel('project'));
            $this->applyFieldDefinitionMetadata($projectField, 'project');
            $format = $this->insertFieldAfter($format, 'department', 'project', $projectField);
        }

        return $format;
    }

    private function insertFieldAfter(array $format, $afterKey, $newKey, FormField $field)
    {
        $result = array();
        $inserted = false;

        foreach ($format as $key => $value) {
            $result[$key] = $value;
            if ($key === $afterKey) {
                $result[$newKey] = $field;
                $inserted = true;
            }
        }

        if (!$inserted) {
            $result[$newKey] = $field;
        }

        return $result;
    }

    private function moveFieldBefore(array $format, $fieldKey, $beforeKey)
    {
        if (!array_key_exists($fieldKey, $format) || !array_key_exists($beforeKey, $format) || $fieldKey === $beforeKey) {
            return $format;
        }

        $keys = array_keys($format);
        $fieldIndex = array_search($fieldKey, $keys, true);
        $beforeIndex = array_search($beforeKey, $keys, true);
        if ($fieldIndex === false || $beforeIndex === false || $fieldIndex < $beforeIndex) {
            return $format;
        }

        $field = $format[$fieldKey];
        unset($format[$fieldKey]);

        $result = array();
        foreach ($format as $key => $value) {
            if ($key === $beforeKey) {
                $result[$fieldKey] = $field;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function applyFieldDefinitionMetadata(FormField $field, $fieldName)
    {
        if (!empty($this->definition[$fieldName]['validate'])) {
            $field->addConstraint($this->definition[$fieldName]['validate']);
        }

        if (!empty($this->definition[$fieldName]['size'])) {
            $field->setMaxLength($this->definition[$fieldName]['size']);
        }
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
