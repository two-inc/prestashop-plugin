<?php

/**
 * Seed the one cart shape the "Default shipping tax code" fallback
 * (TWO-25200) exists for, on a running PrestaShop: a cart whose shipping is
 * priced but whose delivery option belongs to no carrier, so nothing in
 * PrestaShop declares a shipping tax rules group for the order.
 *
 * Runs INSIDE the PrestaShop container as www-data (see
 * dev/ci/seed-carrierless-cart.sh, which also installs the fixture module
 * that does the delivery-option injection).
 *
 * Everything is written through ObjectModel / Configuration - never SQL - so
 * this exercises the same write paths a merchant's own setup would, and stays
 * correct across PrestaShop schema changes.
 *
 * Idempotent: re-running reuses the customer, address, tax fixtures and cart
 * it created the first time (looked up through the ids it published), so both
 * a CI job and a local `make seed-carrierless-cart` can be run repeatedly.
 *
 * Ids and amounts are published as Configuration keys rather than printed,
 * so the probe (tests/integration/default-shipping-tax-code.php) and the
 * fixture module can pick them up without parsing stdout.
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}

const PROBE_EMAIL = 'carrierless-probe@example.com';
const PROBE_TAX_RATE = 25.0;
const PROBE_LABEL = 'Two carrier-less probe';

/**
 * @param string $key
 * @return int
 */
function probeStoredId($key)
{
    $value = Configuration::get($key);

    return $value === false ? 0 : (int) $value;
}

/**
 * Shop default country, activated if the install left it off - the address
 * below has to sit in a country the tax rule can match.
 *
 * @return Country
 */
function probeCountry()
{
    $country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
    if (!Validate::isLoadedObject($country)) {
        throw new RuntimeException('shop default country (PS_COUNTRY_DEFAULT) does not load');
    }
    if (!$country->active) {
        $country->active = true;
        $country->update();
    }

    return $country;
}

/**
 * Make sure at least one carrier actually delivers to the probe country.
 *
 * COUNTER-INTUITIVE BUT LOAD-BEARING. The shape being reproduced is "no
 * carrier declares the shipping tax", so breaking carrier coverage looks like
 * the way to get there. It is the opposite. Core's
 * Cart::getDeliveryOptionList() discards the whole option list on its
 * no-carrier sentinel and returns BEFORE the actionFilterDeliveryOptionList
 * hook the fixture injects through - and Cart::getOrderTotal(*, ONLY_SHIPPING)
 * derives from that same list, so a coverage gap yields shipping of 0.00,
 * no SHIPPING_FEE line, and nothing exercised. Coverage must be INTACT so the
 * hook runs and can replace the list with a carrier-less option.
 *
 * A stock install activates a country but does not necessarily attach its zone
 * to any carrier (the dev Makefile does this for the Two-supported countries
 * too, at install time).
 *
 * @param Country $country
 * @return void
 */
function probeCarrierCoverage($country)
{
    $id_zone = (int) $country->id_zone;
    if ($id_zone <= 0) {
        throw new RuntimeException('country ' . $country->iso_code . ' has no zone');
    }

    $covered = false;
    foreach ((array) Carrier::getCarriers((int) Configuration::get('PS_LANG_DEFAULT'), true, false, false, null, Carrier::ALL_CARRIERS) as $row) {
        $carrier = new Carrier((int) $row['id_carrier']);
        if (!Validate::isLoadedObject($carrier)) {
            continue;
        }
        foreach ((array) $carrier->getZones() as $zone) {
            if ((int) $zone['id_zone'] === $id_zone) {
                $covered = true;
                continue 2;
            }
        }
        if ($carrier->addZone($id_zone)) {
            $covered = true;
        }
    }

    if (!$covered) {
        throw new RuntimeException(
            'no carrier covers zone ' . $id_zone . ' (' . $country->iso_code . '); the delivery option '
            . 'list would be discarded before the injection hook runs'
        );
    }
}

/**
 * A tax rules group declaring PROBE_TAX_RATE in the shop's default country.
 *
 * A stock install can have NO tax rules groups at all (a PS_COUNTRY=NO
 * install has none), so this creates its own rather than depending on the
 * catalogue happening to contain a usable one.
 *
 * @param Country $country
 * @param int $id_lang
 * @return int Tax rules group id
 */
function probeTaxRulesGroup($country, $id_lang)
{
    $existing = probeStoredId('TWO_CARRIERLESS_TEST_TRG');
    if ($existing > 0 && Validate::isLoadedObject(new TaxRulesGroup($existing))) {
        return $existing;
    }

    $tax = new Tax();
    $tax->rate = PROBE_TAX_RATE;
    $tax->active = true;
    $tax->name = array($id_lang => PROBE_LABEL . ' ' . (int) PROBE_TAX_RATE . '%');
    if (!$tax->add()) {
        throw new RuntimeException('could not create the probe Tax');
    }

    $group = new TaxRulesGroup();
    $group->name = PROBE_LABEL . ' ' . (int) PROBE_TAX_RATE . '%';
    $group->active = true;
    if (!$group->add()) {
        throw new RuntimeException('could not create the probe TaxRulesGroup');
    }

    $rule = new TaxRule();
    $rule->id_tax_rules_group = (int) $group->id;
    $rule->id_country = (int) $country->id;
    $rule->id_state = 0;
    $rule->zipcode_from = 0;
    $rule->zipcode_to = 0;
    $rule->id_tax = (int) $tax->id;
    $rule->behavior = 0;
    if (!$rule->add()) {
        throw new RuntimeException('could not create the probe TaxRule');
    }

    return (int) $group->id;
}

/**
 * @param int $id_lang
 * @return Customer
 */
function probeCustomer($id_lang)
{
    $id_existing = probeStoredId('TWO_CARRIERLESS_TEST_ID_CUSTOMER');
    if ($id_existing > 0) {
        $customer = new Customer($id_existing);
        if (Validate::isLoadedObject($customer)) {
            return $customer;
        }
    }

    $customer = new Customer();
    $customer->firstname = 'Carrierless';
    $customer->lastname = 'Probe';
    $customer->email = PROBE_EMAIL;
    $customer->passwd = Tools::hash('probe-' . uniqid('', true));
    $customer->id_lang = (int) $id_lang;
    $customer->active = true;
    if (!$customer->add()) {
        throw new RuntimeException('could not create the probe Customer');
    }

    return $customer;
}

/**
 * The tax address. Deliberately carries a company but NO vat_number: a
 * vat_number outside VATNUMBER_COUNTRY zeroes the resolved rate (the B2B
 * exemption core's Product::priceCalculation applies and the module mirrors in
 * getTwoConfiguredTaxRateDecimalForGroup), which would make a 25% declaration
 * silently resolve to 0% and the probe's taxed scenario meaningless.
 *
 * @param Customer $customer
 * @param Country $country
 * @return Address
 */
function probeAddress($customer, $country)
{
    $id_existing = probeStoredId('TWO_CARRIERLESS_TEST_ID_ADDRESS');
    if ($id_existing > 0) {
        $address = new Address($id_existing);
        if (Validate::isLoadedObject($address)) {
            return $address;
        }
    }

    $address = new Address();
    $address->id_customer = (int) $customer->id;
    $address->id_country = (int) $country->id;
    $address->alias = PROBE_LABEL;
    $address->firstname = $customer->firstname;
    $address->lastname = $customer->lastname;
    $address->company = 'Carrierless Probe AS';
    $address->address1 = 'Testveien 1';
    $address->postcode = '0150';
    $address->city = 'Oslo';
    $address->phone = '12345678';
    if (!$address->add()) {
        throw new RuntimeException('could not create the probe Address');
    }

    return $address;
}

/**
 * Any active, orderable product. The default catalogue supplies one; a
 * catalogue-less install gets a minimal one created here so the probe does
 * not depend on demo data.
 *
 * @param int $id_lang
 * @return int Product id
 */
function probeProductId($id_lang)
{
    foreach ((array) Product::getProducts((int) $id_lang, 0, 20, 'id_product', 'ASC', false, true) as $row) {
        $product = new Product((int) $row['id_product']);
        if (Validate::isLoadedObject($product) && $product->active && (float) $product->price > 0) {
            return (int) $product->id;
        }
    }

    $product = new Product();
    $product->name = array($id_lang => PROBE_LABEL . ' product');
    $product->link_rewrite = array($id_lang => 'two-carrierless-probe-product');
    $product->price = 100.0;
    $product->active = true;
    $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
    $product->minimal_quantity = 1;
    if (!$product->add()) {
        throw new RuntimeException('could not create a probe Product');
    }
    StockAvailable::setQuantity((int) $product->id, 0, 100);

    return (int) $product->id;
}

/**
 * Populate the CLI Context.
 *
 * Not optional plumbing: a CLI request has no employee and no shopper cookie,
 * and core reaches into the context from inside ordinary cart operations -
 * Cart::updateQty fires actionCartUpdateQuantityBefore, whose default-theme
 * listeners price the product and throw "If no employee is assigned in the
 * context, cart ID must be provided" without it, and
 * Cart::getDeliveryOptionList() reads the cookie's id_lang/id_customer
 * directly.
 *
 * @param Customer $customer
 * @param Country $country
 * @param int $id_lang
 * @return void
 */
function probeApplyContext($customer, $country, $id_lang)
{
    $context = Context::getContext();
    $context->customer = $customer;
    $context->country = $country;
    $context->language = new Language($id_lang);
    $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
    if (is_object($context->cookie)) {
        $context->cookie->id_lang = (int) $id_lang;
        $context->cookie->id_customer = (int) $customer->id;
        $context->cookie->id_currency = (int) $context->currency->id;
    }
}

/**
 * The cart itself: a real product line, and a delivery selection pointing at
 * the fixture module's carrier-0 option.
 *
 * `delivery_option` / `id_carrier` are set as ObjectModel fields (both are
 * declared on Cart) rather than through Cart::setDeliveryOption(), which
 * validates the key against the option list and would need the fixture armed
 * and the list cache warm just to accept it. What is being reproduced here is
 * a cart that ALREADY carries that selection.
 *
 * @param Customer $customer
 * @param Address $address
 * @param int $id_lang
 * @param int $id_product
 * @return Cart
 */
function probeCart($customer, $address, $id_lang, $id_product)
{
    $id_existing = probeStoredId('TWO_CARRIERLESS_TEST_ID_CART');
    if ($id_existing > 0) {
        $cart = new Cart($id_existing);
        if (Validate::isLoadedObject($cart) && $cart->nbProducts() > 0) {
            return $cart;
        }
    }

    $cart = new Cart();
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_lang = (int) $id_lang;
    $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cart->id_shop = (int) Context::getContext()->shop->id;
    $cart->id_shop_group = (int) Context::getContext()->shop->id_shop_group;
    $cart->recyclable = 0;
    $cart->gift = 0;
    if (!$cart->add()) {
        throw new RuntimeException('could not create the probe Cart');
    }
    Context::getContext()->cart = $cart;

    if (!$cart->updateQty(2, $id_product)) {
        throw new RuntimeException('could not add the probe product to the cart');
    }

    // Carrier 0 with a delivery option selected: the custom-logistics shape.
    $cart->delivery_option = json_encode(array((int) $address->id => '0,'));
    $cart->id_carrier = 0;
    if (!$cart->update()) {
        throw new RuntimeException('could not store the carrier-less delivery selection');
    }

    return new Cart((int) $cart->id);
}

$id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$country = probeCountry();
probeCarrierCoverage($country);
$id_tax_rules_group = probeTaxRulesGroup($country, $id_lang);
$customer = probeCustomer($id_lang);
probeApplyContext($customer, $country, $id_lang);
$address = probeAddress($customer, $country);
$id_product = probeProductId($id_lang);
$cart = probeCart($customer, $address, $id_lang, $id_product);

Configuration::updateValue('TWO_CARRIERLESS_TEST_TRG', (int) $id_tax_rules_group);
Configuration::updateValue('TWO_CARRIERLESS_TEST_RATE', (string) PROBE_TAX_RATE);
Configuration::updateValue('TWO_CARRIERLESS_TEST_ID_CUSTOMER', (int) $customer->id);
Configuration::updateValue('TWO_CARRIERLESS_TEST_ID_ADDRESS', (int) $address->id);
Configuration::updateValue('TWO_CARRIERLESS_TEST_ID_CART', (int) $cart->id);
// Arm the fixture module: shipping priced at 29.00 gross / 23.20 net, i.e.
// 5.80 tax, which is exactly PROBE_TAX_RATE on that net. The module asserts a
// declared rate against the applied amounts
// (assertTwoDeclaredRateReconcilesWithAmounts), so the fixture's pricing and
// the declared group have to agree or every scenario fails for that reason
// instead of the one under test. The probe overrides these per scenario.
Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', '29.00');
Configuration::updateValue('TWO_CARRIERLESS_TEST_NET', '23.20');

echo 'carrier-less cart seeded: cart=' . (int) $cart->id
    . ' customer=' . (int) $customer->id
    . ' address=' . (int) $address->id
    . ' country=' . $country->iso_code
    . ' tax_rules_group=' . (int) $id_tax_rules_group . ' (' . (int) PROBE_TAX_RATE . '%)'
    . ' product=' . (int) $id_product
    . PHP_EOL;
