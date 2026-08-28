<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

class CustomerAddressFormatter extends CustomerAddressFormatterCore
{
    public function __construct(Country $country, $translator, array $availableCountries)
    {
        parent::__construct($country, $translator, $availableCountries);
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
        // selector here - sole traders enrol from an entry point folded
        // into the company search control itself (TWO-40; formerly a
        // separate payment-step Business / Sole trader toggle, TWO-24755),
        // which autofills this field from their Two registration once
        // enrolled; matches the Magento and WooCommerce plugins (the
        // third-account_type-option approach previously here has been
        // dropped, see TWO-24755).

        if (isset($format['phone']) && $format['phone'] instanceof FormField) {
            $format['phone']->setType('tel');
            $format['phone']->setRequired(true);
        }

        // Department and project are deliberately NOT injected here any more.
        // They now render inside the Two payment tile at the payment
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
}
