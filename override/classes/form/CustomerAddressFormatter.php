<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

class CustomerAddressFormatter extends CustomerAddressFormatterCore
{
    private $translator;
    private $definition;

    public function __construct(Country $country, $translator, array $availableCountries)
    {
        parent::__construct($country, $translator, $availableCountries);
        $this->translator = $translator;
        $this->definition = Address::$definition['fields'];
    }

    public function setCountry(Country $country)
    {
        parent::setCountry($country);

        return $this;
    }

    public function getCountry()
    {
        return parent::getCountry();
    }

    public function getFormat()
    {
        $format = parent::getFormat();
        if (!is_array($format) || !Module::isInstalled('twopayment') || !Module::isEnabled('twopayment')) {
            return $format;
        }

        $format = $this->moveFieldBefore($format, 'id_country', 'company');

        // B2B checkout: the company field is always present (the buyer
        // types or searches their company). There is no account-type
        // selector here - sole traders enrol through the payment-step
        // Business / Sole trader toggle (TWO-24755), which autofills this
        // field from their Two registration once enrolled; matches the
        // Magento and WooCommerce plugins (the third-account_type-option
        // approach previously here has been dropped, see TWO-24755).
        //
        // The placeholder is the empty-field hint (TWO-25288). Its wording was
        // replaced rather than joined by a second hint: there is exactly one
        // slot in an empty field, and a message row hanging under a field the
        // buyer has not touched yet is noise. The browser JS applies the same
        // wording when this slot is empty, which covers a theme rendering its
        // own address form.
        //
        // Not applied while the shop's API key is KNOWN not to verify (TWO-25326):
        // Two is withheld from checkout entirely in that state, so nothing
        // consumes a captured company and the module mounts no search control -
        // leaving the field a plain text input that this hint would be telling
        // the buyer to search. Same predicate the checkout JS gate is
        // handed, so the two halves of the affordance cannot disagree; an
        // as-yet-unknown verdict leaves the form untouched, since a cold cache is
        // not evidence of a broken shop and this render may not go to the network
        // to find out.
        // The browser strips the wording too, for a page rendered before the
        // verdict changed; this is the half that survives a back-office
        // translation of the core string, which the browser cannot recognise as
        // ours.
        if (isset($format['company']) && $format['company'] instanceof FormField && $this->twoCompanySearchAvailable()) {
            $format['company']->addAvailableValue('placeholder', $this->translator->trans('Enter company name to search', [], 'Shop.Forms.Labels'));
        }

        if (isset($format['phone']) && $format['phone'] instanceof FormField) {
            $format['phone']->setType('tel');
            $format['phone']->setRequired(true);
        }

        // Department and project are deliberately NOT injected here any more
        // (ABN-472). They now render inside the Two payment tile at the payment
        // step, gated by the same PS_TWO_ENABLE_DEPARTMENT /
        // PS_TWO_ENABLE_PROJECT switches, alongside the new purchase-order-
        // number and invoice-email fields. Two reasons, both fatal to the old
        // placement: PrestaShop collects the SHIPPING address first and only
        // reveals the billing block when the buyer ticks "Billing address
        // differs from shipping address", so most buyers never saw either
        // field; and nothing persisted the values anyway, because the address
        // table has no such columns, so the order payload sent them empty
        // regardless. Do not re-add them here.

        return $format;
    }

    /**
     * Whether the search-mode hint on the company field is warranted right now
     * (TWO-25326) - which is to say, whether the module is in a state where a
     * captured company is still good for anything.
     *
     * Asked THROUGH the module rather than by testing categories here, so the
     * server-rendered hint and the browser-side control - two halves of ONE
     * affordance - cannot end up on different policies (review round 4). That
     * method is cache-only by contract, which is what keeps an address-form
     * render (this also runs on my-account pages) off the network, and it treats
     * "nothing known yet" as warranted rather than as broken.
     *
     * Best-effort and fail-OPEN: an override that cannot get an answer at all
     * must keep rendering the address form it has always rendered, never take a
     * hint away from a shop that is fine. Throwable, not Exception: a TypeError
     * or an Error out of the module's own construction would otherwise escape
     * into every address form on the shop and break the address step outright.
     *
     * @return bool
     */
    private function twoCompanySearchAvailable()
    {
        try {
            $module = Module::getInstanceByName('twopayment');
            if (is_object($module)) {
                // No method_exists() guard: an instance too old to answer raises
                // an Error, which the catch below turns into the same fail-open
                // as any other failure to get an answer. The guard was a second
                // spelling of one behaviour, and an unpinnable one - removing it
                // changed nothing any test could see (round 6).
                return (bool) $module->isTwoCompanySearchAffordanceWarranted();
            }
        } catch (Throwable $e) {
            // Fall through to fail-open below.
        }

        return true;
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
            case 'companyid':
                return $this->translator->trans('Company ID', [], 'Shop.Forms.Labels');
            default:
                return $field;
        }
    }
}
