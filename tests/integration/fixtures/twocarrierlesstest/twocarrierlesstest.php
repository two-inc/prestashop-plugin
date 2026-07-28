<?php
/**
 * TEST FIXTURE - NOT PART OF THE SHIPPED TWO MODULE.
 *
 * Reproduces the one cart shape the "Default shipping tax code" fallback
 * (TWO-25200) exists for: shipping that is priced, and a delivery option that
 * belongs to no carrier, so nothing in PrestaShop declares a shipping tax
 * rules group for the order.
 *
 * WHY A MODULE IS NEEDED AT ALL. Breaking carrier coverage does not produce
 * that shape. Core's Cart::getDeliveryOptionList() discards the entire option
 * list on its no-carrier sentinel (`count($package['carrier_list']) == 1 &&
 * current(...) == 0` -> `return []`, Cart.php:2921 on 8.2.7), and
 * Cart::getOrderTotal(*, ONLY_SHIPPING) derives from that same list - so with
 * coverage broken the shipping cost is simply 0 and the fallback is never
 * reached. The reachable shape is a list that core built successfully and a
 * module then REPLACED with an option keyed to carrier 0, which is exactly
 * what a custom-logistics integration does.
 *
 * Placement matters: actionFilterDeliveryOptionList (Cart.php:3163) fires
 * AFTER that sentinel, so carrier coverage must stay INTACT for this hook to
 * run at all. This fixture injects; it never breaks carriers.
 *
 * Amounts come from configuration rather than being hard-coded so one fixture
 * serves both a taxed and a zero-tax scenario:
 *   TWO_CARRIERLESS_TEST_GROSS - shipping gross; <= 0 (the default) makes this
 *                               fixture inert, so merely installing it changes
 *                               nothing
 *   TWO_CARRIERLESS_TEST_NET   - shipping net (== gross for a zero-tax run)
 *
 * @see dev/ci/seed-carrierless-cart.sh
 * @see tests/integration/default-shipping-tax-code.php
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Twocarrierlesstest extends Module
{
    const CONFIG_GROSS = 'TWO_CARRIERLESS_TEST_GROSS';
    const CONFIG_NET = 'TWO_CARRIERLESS_TEST_NET';

    public function __construct()
    {
        $this->name = 'twocarrierlesstest';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Two';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'Two carrier-less shipping test fixture';
        $this->description = 'Test fixture. Injects a priced delivery option that belongs to no carrier, '
            . 'so integration tests can exercise the Default shipping tax code fallback. Inert until '
            . self::CONFIG_GROSS . ' is set to a positive amount.';
    }

    public function install()
    {
        return parent::install() && $this->registerHook('actionFilterDeliveryOptionList');
    }

    /**
     * Replace the cart's delivery options with a single option belonging to
     * carrier 0.
     *
     * `$params['delivery_option_list']` is a reference slot (Hook::exec is
     * called with `&$delivery_option_list`), and PHP preserves reference slots
     * when the args array is copied into this method - so writing through it
     * mutates core's list.
     *
     * @param array $params
     * @return void
     */
    public function hookActionFilterDeliveryOptionList($params)
    {
        $gross = round((float) Configuration::get(self::CONFIG_GROSS), 2);
        if ($gross <= 0) {
            // Inert: installed but not armed.
            return;
        }
        $net = round((float) Configuration::get(self::CONFIG_NET), 2);
        if ($net <= 0 || $net > $gross) {
            $net = $gross;
        }

        $cart = isset($params['cart']) ? $params['cart'] : null;
        if (!is_object($cart) || !Validate::isLoadedObject($cart)) {
            return;
        }
        $id_address = (int) $cart->id_address_delivery;
        if ($id_address <= 0) {
            return;
        }

        // Same per-carrier shape core builds (Cart.php:3050-3064) plus the
        // fields its own post-processing loop adds (total_price_*, is_free,
        // position, logo), because callers read those and core will not run
        // that loop again after this hook.
        $params['delivery_option_list'] = array(
            $id_address => array(
                '0,' => array(
                    'is_best_price' => true,
                    'is_best_grade' => true,
                    'unique_carrier' => true,
                    'carrier_list' => array(
                        0 => array(
                            'price_with_tax' => $gross,
                            'price_without_tax' => $net,
                            'instance' => new Carrier(0),
                            'package_list' => array(0),
                            'product_list' => $cart->getProducts(),
                            'logo' => false,
                        ),
                    ),
                    'total_price_with_tax' => $gross,
                    'total_price_without_tax' => $net,
                    'is_free' => false,
                    'position' => 0,
                ),
            ),
        );
    }
}
