<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * One compact JSON record of a cart's totals, lines, shipping, overrides and
 * price hooks, written to PrestaShop's log when a tax or totals gate fails
 * (TWO-26064), so one failed order is enough to tell which cart shape caused it.
 *
 * Every read that can reach third-party code (overrides, hooked modules) is
 * guarded: a snapshot must never turn a refused order into a fatal.
 */
class TwoDiscrepancySnapshot
{
    const LOG_OBJECT_TYPE = 'TwoDiscrepancySnapshot';
    const SCHEMA_VERSION = 1;
    // ps_log.message is TEXT (64KB) on 1.7.x, which also stores it addslashes()-escaped.
    const MAX_BYTES = 32000;
    const MAX_ROWS = 40;
    const MAX_STRING = 120;
    const AMOUNT_TOLERANCE = 0.01;

    const HOOKS = array(
        'actionCartGetPackageShippingCost',
        'actionProductPriceCalculation',
        'displayCarrierExtraContent',
        'actionCartSave',
    );

    const TOTAL_TYPES = array(
        'ONLY_PRODUCTS',
        'ONLY_DISCOUNTS',
        'BOTH',
        'BOTH_WITHOUT_SHIPPING',
        'ONLY_SHIPPING',
        'ONLY_WRAPPING',
        'ONLY_PHYSICAL_PRODUCTS_WITHOUT_SHIPPING',
    );

    const LINE_ITEM_KEYS = array(
        'type', 'name', 'quantity', 'unit_price', 'net_amount', 'discount_amount',
        'tax_amount', 'gross_amount', 'tax_rate', 'tax_class_name',
    );

    // Dropped first when the record would not fit.
    const SHEDDABLE = array('sent_line_items', 'hooks', 'overrides', 'cart_rules', 'delivery_options', 'products');

    /**
     * @param Cart $cart
     * @param array|null $gate {name, label, numbers}; null for a debug-mode baseline
     * @param array|null $lineItems the payload lines built so far, if any
     * @param callable $declaredRate fn(int $id_tax_rules_group): float decimal rate
     * @param int|null $defaultShippingGroup the module's Default shipping tax code
     * @return array
     */
    public static function build($cart, $gate, $lineItems, $declaredRate, $defaultShippingGroup)
    {
        $snapshot = array(
            'v' => self::SCHEMA_VERSION,
            'id_cart' => (int) $cart->id,
            'gate' => $gate,
            'totals' => self::guard(function () use ($cart) {
                return self::totals($cart);
            }),
            'products' => self::guard(function () use ($cart, $declaredRate) {
                return self::products($cart, $declaredRate);
            }),
            'shipping' => self::guard(function () use ($cart) {
                return self::shipping($cart);
            }),
            'delivery_options' => self::guard(function () use ($cart) {
                return self::deliveryOptions($cart);
            }),
            'overrides' => self::guard(function () {
                return self::overrides();
            }),
            'hooks' => self::guard(function () {
                return self::hooks();
            }),
            'cart_rules' => self::guard(function () use ($cart) {
                return self::cartRules($cart);
            }),
            'config' => self::guard(function () use ($cart, $defaultShippingGroup) {
                return self::config($cart, $defaultShippingGroup);
            }),
            'address' => self::guard(function () use ($cart) {
                return self::addresses($cart);
            }),
            'sent_line_items' => is_array($lineItems) ? self::lineItems($lineItems) : null,
        );
        $snapshot['shape'] = self::classify($snapshot);

        return $snapshot;
    }

    /**
     * Map a snapshot to the cart shape that explains it (README "Discrepancy snapshot").
     *
     * @param array $snapshot
     * @return string A|B|C|D|other
     */
    public static function classify(array $snapshot)
    {
        $totals = isset($snapshot['totals']) && is_array($snapshot['totals']) ? $snapshot['totals'] : array();
        $shipIncl = self::num($totals, array('ONLY_SHIPPING', 'incl'));
        $shipExcl = self::num($totals, array('ONLY_SHIPPING', 'excl'));
        $residual = self::num($totals, array('residual', 'incl'));
        $carrierGroup = (int) self::num($snapshot, array('shipping', 'carrier_tax_rules_group'));

        if (abs($residual) > self::AMOUNT_TOLERANCE && abs($shipIncl) <= self::AMOUNT_TOLERANCE) {
            return 'C';
        }
        if ($shipIncl > self::AMOUNT_TOLERANCE && $carrierGroup <= 0) {
            return abs($shipIncl - $shipExcl) <= self::AMOUNT_TOLERANCE ? 'A' : 'B';
        }
        if (self::hasUnexplainedProductDelta($snapshot) && self::hasPriceOverrideOrHook($snapshot)) {
            return 'D';
        }

        return 'other';
    }

    /**
     * JSON for the log, shedding the bulkiest sections until it fits MAX_BYTES.
     *
     * @param array $snapshot
     * @return string
     */
    public static function encode(array $snapshot)
    {
        // Shop images ship serialize_precision=17, which prints 47.8 as 47.799999999999997.
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return self::encodeWithinLimit($snapshot);
        } finally {
            ini_set('serialize_precision', (string) $precision);
        }
    }

    /**
     * The JSON document from a stored log message; 1.7.x stores it pSQL()-escaped.
     *
     * @param string $message
     * @return string|null
     */
    public static function decodeStored($message)
    {
        foreach (array((string) $message, stripslashes((string) $message)) as $candidate) {
            if (is_array(json_decode($candidate, true))) {
                return $candidate;
            }
        }

        return null;
    }

    private static function encodeWithinLimit(array $snapshot)
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0);
        $json = (string) json_encode($snapshot, $flags);
        foreach (self::SHEDDABLE as $key) {
            if (strlen(addslashes($json)) <= self::MAX_BYTES) {
                break;
            }
            $snapshot[$key] = array('dropped' => 'size');
            $json = (string) json_encode($snapshot, $flags);
        }
        if (strlen(addslashes($json)) > self::MAX_BYTES) {
            $json = (string) json_encode(array(
                'v' => self::SCHEMA_VERSION,
                'id_cart' => isset($snapshot['id_cart']) ? $snapshot['id_cart'] : 0,
                'shape' => isset($snapshot['shape']) ? $snapshot['shape'] : 'other',
                'dropped' => 'size',
            ), $flags);
        }

        return $json;
    }

    /**
     * @param callable $read
     * @return mixed the section, or {error} when it raised
     */
    private static function guard($read)
    {
        try {
            return $read();
        } catch (Throwable $e) {
            return array('error' => self::str(get_class($e) . ': ' . $e->getMessage()));
        }
    }

    private static function totals($cart)
    {
        $totals = array();
        foreach (self::TOTAL_TYPES as $name) {
            if (!defined('Cart::' . $name)) {
                continue;
            }
            $type = constant('Cart::' . $name);
            $totals[$name] = array(
                'incl' => self::guard(function () use ($cart, $type) {
                    return round((float) $cart->getOrderTotal(true, $type), 2);
                }),
                'excl' => self::guard(function () use ($cart, $type) {
                    return round((float) $cart->getOrderTotal(false, $type), 2);
                }),
            );
        }
        // Discounts come back positive and BOTH subtracts them.
        $residual = array();
        foreach (array('incl', 'excl') as $side) {
            $residual[$side] = round(
                self::num($totals, array('BOTH', $side))
                - self::num($totals, array('ONLY_PRODUCTS', $side))
                - self::num($totals, array('ONLY_SHIPPING', $side))
                - self::num($totals, array('ONLY_WRAPPING', $side))
                + self::num($totals, array('ONLY_DISCOUNTS', $side)),
                2
            ) + 0.0;
        }
        $totals['residual'] = $residual;

        return $totals;
    }

    private static function products($cart, $declaredRate)
    {
        $rows = array();
        foreach (array_slice((array) $cart->getProducts(), 0, self::MAX_ROWS) as $row) {
            $total = round((float) (isset($row['total']) ? $row['total'] : 0), 2);
            $totalWt = round((float) (isset($row['total_wt']) ? $row['total_wt'] : 0), 2);
            $group = (int) self::guard(function () use ($row) {
                return Product::getIdTaxRulesGroupByIdProduct((int) $row['id_product']);
            });
            $declared = self::guard(function () use ($declaredRate, $group) {
                return round((float) call_user_func($declaredRate, $group), 6);
            });
            $declaredNum = is_float($declared) ? $declared : 0.0;
            $rows[] = array(
                'id_product' => (int) $row['id_product'],
                'id_product_attribute' => (int) (isset($row['id_product_attribute']) ? $row['id_product_attribute'] : 0),
                'qty' => (int) (isset($row['cart_quantity']) ? $row['cart_quantity'] : 0),
                'price' => round((float) (isset($row['price']) ? $row['price'] : 0), 6),
                'price_wt' => round((float) (isset($row['price_wt']) ? $row['price_wt'] : 0), 6),
                'total' => $total,
                'total_wt' => $totalWt,
                'ecotax' => round((float) (isset($row['ecotax']) ? $row['ecotax'] : 0), 6),
                'id_tax_rules_group' => $group,
                'declared_rate' => $declared,
                'implied_rate' => $total != 0.0 ? round(($totalWt - $total) / $total, 6) : null,
                'delta' => round($totalWt - $total * (1 + $declaredNum), 2) + 0.0,
            );
        }

        return $rows;
    }

    private static function shipping($cart)
    {
        $idCarrier = (int) $cart->id_carrier;
        $raw = isset($cart->delivery_option) ? (string) $cart->delivery_option : '';
        $option = json_decode($raw, true);

        return array(
            'id_carrier' => $idCarrier,
            'carrier_tax_rules_group' => $idCarrier > 0 ? self::guard(function () use ($idCarrier) {
                return (int) Carrier::getIdTaxRulesGroupByIdCarrier($idCarrier);
            }) : 0,
            'delivery_option' => is_array($option) ? $option : self::str($raw),
            'package_cost_incl' => self::guard(function () use ($cart, $idCarrier) {
                return round((float) $cart->getPackageShippingCost($idCarrier > 0 ? $idCarrier : null, true), 2);
            }),
            'package_cost_excl' => self::guard(function () use ($cart, $idCarrier) {
                return round((float) $cart->getPackageShippingCost($idCarrier > 0 ? $idCarrier : null, false), 2);
            }),
        );
    }

    private static function deliveryOptions($cart)
    {
        $summary = array();
        foreach ((array) $cart->getDeliveryOptionList() as $idAddress => $options) {
            foreach ((array) $options as $key => $option) {
                if (count($summary) >= self::MAX_ROWS) {
                    break 2;
                }
                $carriers = array();
                $list = isset($option['carrier_list']) && is_array($option['carrier_list']) ? $option['carrier_list'] : array();
                foreach ($list as $idCarrier => $entry) {
                    $instance = isset($entry['instance']) ? $entry['instance'] : null;
                    $carriers[] = array(
                        'id_carrier' => (int) $idCarrier,
                        'tax_rules_group' => self::guard(function () use ($instance, $idCarrier) {
                            return is_object($instance) && method_exists($instance, 'getIdTaxRulesGroup')
                                ? (int) $instance->getIdTaxRulesGroup()
                                : (int) Carrier::getIdTaxRulesGroupByIdCarrier((int) $idCarrier);
                        }),
                    );
                }
                $summary[] = array(
                    'id_address' => (int) $idAddress,
                    'key' => self::str((string) $key),
                    'carriers' => $carriers,
                    'price_incl' => round((float) (isset($option['total_price_with_tax']) ? $option['total_price_with_tax'] : 0), 2),
                    'price_excl' => round((float) (isset($option['total_price_without_tax']) ? $option['total_price_without_tax'] : 0), 2),
                );
            }
        }

        return $summary;
    }

    private static function overrides()
    {
        $files = array();
        foreach (array('Cart', 'Product', 'Carrier', 'Order') as $class) {
            $files[$class] = self::guard(function () use ($class) {
                $file = (string) (new ReflectionClass($class))->getFileName();

                // Without an override the autoloader eval()s an empty `X extends XCore`.
                return strpos($file, "eval()'d") !== false ? 'core' : self::relativePath($file);
            });
        }
        $added = array();
        $overridden = array();
        if (class_exists('CartCore', false) && get_parent_class('Cart') === 'CartCore') {
            foreach ((new ReflectionClass('Cart'))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== 'Cart') {
                    continue;
                }
                if (method_exists('CartCore', $method->getName())) {
                    $overridden[] = $method->getName();
                } else {
                    $added[] = $method->getName();
                }
            }
        }

        return array(
            'files' => $files,
            'cart_methods_added' => array_slice($added, 0, self::MAX_ROWS),
            'cart_methods_overridden' => array_slice($overridden, 0, self::MAX_ROWS),
        );
    }

    private static function hooks()
    {
        $hooks = array();
        foreach (self::HOOKS as $hook) {
            $hooks[$hook] = self::guard(function () use ($hook) {
                $names = array();
                $list = Hook::getHookModuleExecList($hook);
                foreach (is_array($list) ? $list : array() as $row) {
                    $names[] = self::str((string) (isset($row['module']) ? $row['module'] : ''));
                }

                return array_slice($names, 0, self::MAX_ROWS);
            });
        }

        return $hooks;
    }

    private static function cartRules($cart)
    {
        $rules = array();
        foreach (array_slice((array) $cart->getCartRules(), 0, self::MAX_ROWS) as $rule) {
            $rules[] = array(
                'id_cart_rule' => (int) (isset($rule['id_cart_rule']) ? $rule['id_cart_rule'] : 0),
                'free_shipping' => !empty($rule['free_shipping']),
                'reduction_percent' => (float) (isset($rule['reduction_percent']) ? $rule['reduction_percent'] : 0),
                'reduction_amount' => (float) (isset($rule['reduction_amount']) ? $rule['reduction_amount'] : 0),
                'reduction_tax' => !empty($rule['reduction_tax']),
                'value_real' => round((float) (isset($rule['value_real']) ? $rule['value_real'] : 0), 2),
                'value_tax_exc' => round((float) (isset($rule['value_tax_exc']) ? $rule['value_tax_exc'] : 0), 2),
            );
        }

        return $rules;
    }

    private static function config($cart, $defaultShippingGroup)
    {
        $currency = new Currency((int) $cart->id_currency);

        return array(
            'ps_version' => defined('_PS_VERSION_') ? _PS_VERSION_ : null,
            'PS_TAX' => Configuration::get('PS_TAX'),
            'PS_TAX_ADDRESS_TYPE' => Configuration::get('PS_TAX_ADDRESS_TYPE'),
            'PS_ROUND_TYPE' => Configuration::get('PS_ROUND_TYPE'),
            'PS_PRICE_ROUND_MODE' => Configuration::get('PS_PRICE_ROUND_MODE'),
            'PS_ATCP_SHIPWRAP' => Configuration::get('PS_ATCP_SHIPWRAP'),
            'PS_GIFT_WRAPPING' => Configuration::get('PS_GIFT_WRAPPING'),
            'PS_GIFT_WRAPPING_PRICE' => Configuration::get('PS_GIFT_WRAPPING_PRICE'),
            'cart_gift' => !empty($cart->gift),
            'currency_iso' => isset($currency->iso_code) ? $currency->iso_code : null,
            'currency_precision' => isset($currency->precision) ? (int) $currency->precision : null,
            'compute_precision' => defined('_PS_PRICE_COMPUTE_PRECISION_') ? (int) _PS_PRICE_COMPUTE_PRECISION_ : null,
            'default_shipping_tax_rules_group' => $defaultShippingGroup,
        );
    }

    // Country, state and whether a VAT number exists: never any other buyer data.
    private static function addresses($cart)
    {
        $out = array();
        foreach (array('delivery' => 'id_address_delivery', 'invoice' => 'id_address_invoice') as $role => $field) {
            $address = new Address((int) $cart->{$field});
            $out[$role] = array(
                'country_iso' => (string) Country::getIsoById((int) $address->id_country),
                'id_state' => (int) $address->id_state,
                'vat_number_present' => trim((string) $address->vat_number) !== '',
            );
        }

        return $out;
    }

    private static function lineItems(array $lineItems)
    {
        $out = array();
        foreach (array_slice($lineItems, 0, self::MAX_ROWS) as $item) {
            $row = array();
            foreach (self::LINE_ITEM_KEYS as $key) {
                if (isset($item[$key])) {
                    $row[$key] = is_string($item[$key]) ? self::str($item[$key]) : $item[$key];
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private static function hasUnexplainedProductDelta(array $snapshot)
    {
        foreach (isset($snapshot['products']) && is_array($snapshot['products']) ? $snapshot['products'] : array() as $row) {
            if (is_array($row) && isset($row['delta']) && abs((float) $row['delta']) > self::AMOUNT_TOLERANCE) {
                return true;
            }
        }

        return false;
    }

    private static function hasPriceOverrideOrHook(array $snapshot)
    {
        $overrides = isset($snapshot['overrides']) && is_array($snapshot['overrides']) ? $snapshot['overrides'] : array();
        foreach (array('cart_methods_added', 'cart_methods_overridden') as $key) {
            if (!empty($overrides[$key])) {
                return true;
            }
        }
        foreach (isset($overrides['files']) && is_array($overrides['files']) ? $overrides['files'] : array() as $file) {
            if (is_string($file) && strpos($file, 'override/') === 0) {
                return true;
            }
        }
        foreach (array('actionProductPriceCalculation', 'actionCartGetPackageShippingCost') as $hook) {
            if (!empty($snapshot['hooks'][$hook]) && !isset($snapshot['hooks'][$hook]['error'])) {
                return true;
            }
        }

        return false;
    }

    private static function num($array, array $path)
    {
        foreach ($path as $key) {
            if (!is_array($array) || !isset($array[$key])) {
                return 0.0;
            }
            $array = $array[$key];
        }

        return is_numeric($array) ? (float) $array : 0.0;
    }

    private static function relativePath($file)
    {
        $root = defined('_PS_ROOT_DIR_') ? rtrim((string) _PS_ROOT_DIR_, '/') . '/' : '';

        return $root !== '' && strpos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
    }

    private static function str($value, $max = self::MAX_STRING)
    {
        $value = (string) $value;
        if (strlen($value) <= $max) {
            return $value;
        }

        // A byte cut can split a UTF-8 character, which json_encode then rejects.
        return (function_exists('mb_strcut') ? mb_strcut($value, 0, $max, 'UTF-8') : substr($value, 0, $max)) . '...';
    }
}
