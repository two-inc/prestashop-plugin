<?php

/**
 * INTEGRATION PROBE - line-item image_url (TWO-26071), against a real
 * PrestaShop engine.
 *
 * getTwoProductItems() takes each line's image from the cart row's id_image
 * rather than looking the product's cover up itself. That is only right if
 * core resolves the row's id_image per combination, which a stubbed cart row
 * cannot show. Core does so in Cart::getProducts(), via
 * Image::getBestImageAttribute() for rows with a combination.
 *
 * This probe builds its own products and cart through ObjectModel, then checks
 * both the raw cart row and the module's image_url for four lines:
 *   - a combination with its own image  -> that image, not the product cover
 *   - a combination without an image    -> the product cover
 *   - a simple product with a cover     -> the cover
 *   - a product with no image at all    -> an empty image_url
 *
 * Hermetic: no browser, no network, no Two credentials. Needs only the module
 * installed.
 *
 * Usage: php tests/integration/line-item-image.php
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}

/**
 * Same kernel boot as default-shipping-tax-code.php: PrestaShop 9 prices a cart
 * through a Symfony service, and a CLI request has no container until one is
 * booted.
 *
 * @return void
 */
function imageProbeBootKernel()
{
    if (PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance() !== null) {
        return;
    }
    foreach (array('FrontKernel', 'AppKernel') as $class) {
        if (!class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }
        $kernel = new $class(defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ ? 'dev' : 'prod', false);
        $kernel->boot();
        $GLOBALS['kernel'] = $kernel;
        PrestaShop\PrestaShop\Adapter\SymfonyContainer::resetStaticCache();
        Context::getContext()->container = $kernel->getContainer();

        return;
    }
    throw new RuntimeException('no bootable PrestaShop kernel class found (tried FrontKernel, AppKernel)');
}

/**
 * @param string $name
 * @param int $id_lang
 * @return Product
 */
function imageProbeProduct($name, $id_lang)
{
    $product = new Product();
    $product->name = array($id_lang => $name);
    $product->link_rewrite = array($id_lang => Tools::str2url($name));
    $product->price = 10;
    $product->active = 1;
    $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
    $product->add();
    $product->addToCategories(array((int) Configuration::get('PS_HOME_CATEGORY')));
    StockAvailable::setQuantity((int) $product->id, 0, 100);

    return $product;
}

/**
 * @param int $id_product
 * @param bool $cover
 * @param int $id_lang
 * @return int
 */
function imageProbeImage($id_product, $cover, $id_lang)
{
    $image = new Image();
    $image->id_product = $id_product;
    $image->cover = $cover ? 1 : null;
    $image->legend = array($id_lang => 'probe');
    $image->add();

    return (int) $image->id;
}

imageProbeBootKernel();
$context = Context::getContext();
$id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$context->language = new Language($id_lang);
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
// PrestaShop 8 records a stock movement against the context employee and fails on none.
$context->employee = new Employee((int) Db::getInstance()->getValue('SELECT MIN(id_employee) FROM `' . _DB_PREFIX_ . 'employee`'));

$group = new AttributeGroup();
$group->name = array($id_lang => 'Probe colour');
$group->public_name = array($id_lang => 'Probe colour');
$group->group_type = 'select';
$group->add();
// Renamed from Attribute in PrestaShop 8.
$attribute_class = class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';

$combo_product = imageProbeProduct('Probe combination lamp', $id_lang);
$cover = imageProbeImage((int) $combo_product->id, true, $id_lang);
$own_image = imageProbeImage((int) $combo_product->id, false, $id_lang);
$combinations = array();
foreach (array('with_image' => $own_image, 'without_image' => 0) as $key => $id_image) {
    $attribute = new $attribute_class();
    $attribute->id_attribute_group = (int) $group->id;
    $attribute->name = array($id_lang => 'Probe ' . $key);
    $attribute->add();

    $combination = new Combination();
    $combination->id_product = (int) $combo_product->id;
    $combination->minimal_quantity = 1;
    $combination->add();
    $combination->setAttributes(array((int) $attribute->id));
    if ($id_image) {
        $combination->setImages(array($id_image));
    }
    StockAvailable::setQuantity((int) $combo_product->id, (int) $combination->id, 100);
    $combinations[$key] = (int) $combination->id;
}
$simple_product = imageProbeProduct('Probe simple lamp', $id_lang);
$simple_cover = imageProbeImage((int) $simple_product->id, true, $id_lang);
$bare_product = imageProbeProduct('Probe imageless lamp', $id_lang);

$cart = new Cart();
$cart->id_lang = $id_lang;
$cart->id_currency = (int) $context->currency->id;
$cart->id_shop = (int) $context->shop->id;
$cart->add();
$context->cart = $cart;

// [id_product, id_product_attribute, expected image id (0 = none), description]
$cases = array(
    array((int) $combo_product->id, $combinations['with_image'], $own_image, 'combination with its own image'),
    array((int) $combo_product->id, $combinations['without_image'], $cover, 'combination without an image falls back to the cover'),
    array((int) $simple_product->id, 0, $simple_cover, 'simple product with a cover'),
    array((int) $bare_product->id, 0, 0, 'product with no image'),
);
foreach ($cases as $case) {
    $cart->updateQty(1, $case[0], $case[1]);
}

// Product lines come out in cart-row order, so pair each with its row by position.
$rows = array();
$item_urls = array();
$items = Module::getInstanceByName('twopayment')->getTwoProductItems($cart);
foreach ($items as $item) {
    if (isset($item['type']) && $item['type'] === 'PHYSICAL') {
        $item_urls[] = (string) $item['image_url'];
    }
}
foreach (array_values($cart->getProducts(true)) as $position => $row) {
    $row['image_url'] = isset($item_urls[$position]) ? $item_urls[$position] : null;
    $rows[$row['id_product'] . '-' . $row['id_product_attribute']] = $row;
}

$failures = array();
if (count($item_urls) !== count($cases)) {
    $failures[] = 'expected ' . count($cases) . ' product lines, got ' . count($item_urls);
}
foreach ($cases as $case) {
    list($id_product, $id_product_attribute, $expected_image, $description) = $case;
    $row = isset($rows[$id_product . '-' . $id_product_attribute]) ? $rows[$id_product . '-' . $id_product_attribute] : null;
    $raw = $row === null ? null : (string) $row['id_image'];
    // Core writes "<id_product>-<id_image>" before PrestaShop 9 and a bare id from 9.
    $resolved = $raw !== null && preg_match('/^(?:\d+-)?(\d+)$/', $raw, $match) ? (int) $match[1] : 0;
    if ($resolved !== $expected_image) {
        $failures[] = $description . ': cart row id_image ' . var_export($raw, true) . ', expected image ' . $expected_image;
    }

    $url = $row === null ? null : $row['image_url'];
    $url_ok = $expected_image === 0
        ? $url === ''
        : $url !== null && preg_match('#(^|/)' . $expected_image . '-home_default[/.]#', $url) === 1;
    if (!$url_ok) {
        $failures[] = $description . ': image_url ' . var_export($url, true) . ', expected image ' . ($expected_image ?: 'none');
    }
    echo ($resolved === $expected_image && $url_ok ? 'ok   ' : 'FAIL ') . $description . ': id_image=' . var_export($raw, true) . ' image_url=' . var_export($url, true) . PHP_EOL;
}

if (!empty($failures)) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'line-item-image: all ' . count($cases) . ' cases pass on PrestaShop ' . _PS_VERSION_ . PHP_EOL;
