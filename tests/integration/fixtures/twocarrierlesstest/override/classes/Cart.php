<?php
/**
 * TEST FIXTURE - see modules/twocarrierlesstest. Reads Configuration rather
 * than the module class, which is not loaded on every request.
 *
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Cart extends CartCore
{
    /**
     * @return float
     */
    public function getExternalShippingCost()
    {
        if (!$this->id) {
            return 0.0;
        }
        $has_row = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'two_test_external_shipping` WHERE `id_cart` = ' . (int) $this->id
        );

        return $has_row ? max(0.0, round((float) Configuration::get('TWO_CARRIERLESS_TEST_GROSS'), 2)) : 0.0;
    }

    /**
     * @return float
     */
    private function getProductSurcharge()
    {
        return (string) Configuration::get('TWO_CARRIERLESS_TEST_MODE') === 'product_surcharge'
            ? round((float) Configuration::get('TWO_CARRIERLESS_TEST_SURCHARGE'), 2)
            : 0.0;
    }

    public function getProducts($refresh = false, $id_product = false, $id_country = null, $fullInfos = true, bool $keepOrderPrices = false)
    {
        $products = parent::getProducts($refresh, $id_product, $id_country, $fullInfos, $keepOrderPrices);
        $surcharge = $this->getProductSurcharge();
        if ($surcharge > 0) {
            // Untaxed: lands on the net and the gross alike, while the line keeps its declared tax rules group.
            foreach ($products as &$row) {
                $unit = $surcharge / max(1, (int) $row['cart_quantity']);
                $row['price'] += $unit;
                $row['price_wt'] += $unit;
                $row['total'] += $surcharge;
                $row['total_wt'] += $surcharge;
            }
            unset($row);
        }

        return $products;
    }

    public function getOrderTotal(
        $withTaxes = true,
        $type = Cart::BOTH,
        $products = null,
        $id_carrier = null,
        $use_cache = false,
        bool $keepOrderPrices = false
    ) {
        $total = parent::getOrderTotal($withTaxes, $type, $products, $id_carrier, $use_cache, $keepOrderPrices);
        // Untaxed on both sides: nothing declares a tax rules group for this cost.
        if ((int) $type === Cart::BOTH && $products === null
            && (string) Configuration::get('TWO_CARRIERLESS_TEST_MODE') === 'external_only') {
            $total += $this->getExternalShippingCost();
        }
        // Core prices product rows itself, so the rows' surcharge has to be added to the totals too.
        if ($products === null && in_array((int) $type, array(Cart::BOTH, Cart::BOTH_WITHOUT_SHIPPING, Cart::ONLY_PRODUCTS), true)) {
            $total += $this->getProductSurcharge() * count(parent::getProducts());
        }

        return $total;
    }
}
