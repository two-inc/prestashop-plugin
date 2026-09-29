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

    // Overriding one of these can move a product line's amounts away from its tax rate.
    const PRICE_METHODS = array(
        'Cart' => array('getOrderTotal', 'getProducts', 'getPackageShippingCost', 'getTotalShippingCost'),
        'Product' => array('getPriceStatic', 'priceCalculation', 'getPrice', 'getTaxesRate', 'getIdTaxRulesGroupByIdProduct'),
        'Carrier' => array('getTaxesRate', 'getIdTaxRulesGroup', 'getIdTaxRulesGroupByIdCarrier'),
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
        // A failed rate lookup logs a row of its own, so each group is asked once per snapshot.
        $rates = array();
        $truncated = array();
        $declaredRate = function ($group) use (&$rates, $declaredRate) {
            if (!array_key_exists($group, $rates)) {
                $rates[$group] = round((float) call_user_func($declaredRate, $group), 6);
            }

            return $rates[$group];
        };
        $snapshot = array(
            'v' => self::SCHEMA_VERSION,
            'id_cart' => (int) $cart->id,
            'gate' => $gate,
            'totals' => self::guard(function () use ($cart) {
                return self::totals($cart);
            }),
            'products' => self::guard(function () use ($cart, $declaredRate, &$truncated) {
                return self::products(self::cap((array) $cart->getProducts(), 'products', $truncated), $declaredRate);
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
            'cart_rules' => self::guard(function () use ($cart, &$truncated) {
                return self::cartRules(self::cap((array) $cart->getCartRules(), 'cart_rules', $truncated));
            }),
            'config' => self::guard(function () use ($cart, $defaultShippingGroup) {
                return self::config($cart, $defaultShippingGroup);
            }),
            'address' => self::guard(function () use ($cart) {
                return self::addresses($cart);
            }),
            'sent_line_items' => is_array($lineItems) ? self::lineItems(self::cap($lineItems, 'sent_line_items', $truncated)) : null,
        );
        if ($truncated !== array()) {
            $snapshot['truncated'] = $truncated;
        }
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
        $both = self::num($snapshot, array('totals', 'BOTH', 'incl'));
        $shipIncl = self::num($snapshot, array('totals', 'ONLY_SHIPPING', 'incl'));
        $shipExcl = self::num($snapshot, array('totals', 'ONLY_SHIPPING', 'excl'));
        $residual = self::num($snapshot, array('totals', 'residual', 'incl'));
        $carrierGroups = self::carrierGroups($snapshot);
        // A shape is a positive identification: any input that errored or is missing proves nothing.
        if ($both === null || $shipIncl === null || $shipExcl === null || $residual === null || $carrierGroups === null) {
            return 'other';
        }

        // Stacked vouchers clamp BOTH to 0, which leaves a residual no hidden cost explains.
        if (abs($residual) > self::AMOUNT_TOLERANCE && abs($shipIncl) <= self::AMOUNT_TOLERANCE && $both > self::AMOUNT_TOLERANCE) {
            return 'C';
        }
        if ($shipIncl > self::AMOUNT_TOLERANCE && $carrierGroups === array(0)) {
            return abs($shipIncl - $shipExcl) <= self::AMOUNT_TOLERANCE ? 'A' : 'B';
        }
        if (self::hasUnexplainedProductDelta($snapshot) && self::hasPriceOverrideOrHook($snapshot)) {
            return 'D';
        }

        return 'other';
    }

    /**
     * total_wt - total x (1 + declared rate), less what ecotax taxed at its own rate adds.
     *
     * @param array $row a products row
     * @return float|null null when an input is missing or errored
     */
    public static function productDelta(array $row)
    {
        $total = self::num($row, array('total'));
        $totalWt = self::num($row, array('total_wt'));
        $qty = self::num($row, array('qty'));
        $declared = self::num($row, array('declared_rate'));
        $ecotax = self::num($row, array('ecotax'));
        if ($total === null || $totalWt === null || $qty === null || $declared === null || $ecotax === null) {
            return null;
        }
        $ecotaxRate = $ecotax != 0.0 ? self::num($row, array('ecotax_rate')) : $declared;
        if ($ecotaxRate === null) {
            return null;
        }

        return round($totalWt - $total * (1 + $declared) - $ecotax * $qty * ($ecotaxRate - $declared), 2) + 0.0;
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
        // Core strip_tags() every log message (pSQL without html_ok), so no raw < or > may reach it.
        $flags = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0);
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
                'gate' => array('name' => isset($snapshot['gate']['name']) ? $snapshot['gate']['name'] : null),
                'shape' => isset($snapshot['shape']) ? $snapshot['shape'] : 'other',
                'dropped' => 'size',
            ), $flags);
        }

        return $json;
    }

    /**
     * @param callable $read
     * @return mixed the section, or {error, code} when it raised
     */
    private static function guard($read)
    {
        try {
            return $read();
        } catch (Throwable $e) {
            // Never the message: third-party code puts buyer addresses in it.
            return array('error' => get_class($e), 'code' => $e->getCode());
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
            $sum = 0.0;
            foreach (array('BOTH' => 1, 'ONLY_PRODUCTS' => -1, 'ONLY_SHIPPING' => -1, 'ONLY_WRAPPING' => -1, 'ONLY_DISCOUNTS' => 1) as $name => $sign) {
                $value = self::num($totals, array($name, $side));
                $sum = $sum === null || $value === null ? null : $sum + $sign * $value;
            }
            $residual[$side] = $sum === null ? null : round($sum, 2) + 0.0;
        }
        $totals['residual'] = $residual;

        return $totals;
    }

    private static function products(array $cartProducts, $declaredRate)
    {
        $ecotaxRate = self::guard(function () use ($declaredRate) {
            return call_user_func($declaredRate, (int) Configuration::get('PS_ECOTAX_TAX_RULES_GROUP_ID'));
        });
        $rows = array();
        foreach ($cartProducts as $row) {
            $total = round((float) (isset($row['total']) ? $row['total'] : 0), 2);
            $totalWt = round((float) (isset($row['total_wt']) ? $row['total_wt'] : 0), 2);
            $group = self::guard(function () use ($row) {
                return (int) Product::getIdTaxRulesGroupByIdProduct((int) $row['id_product']);
            });
            $out = array(
                'id_product' => (int) $row['id_product'],
                'id_product_attribute' => (int) (isset($row['id_product_attribute']) ? $row['id_product_attribute'] : 0),
                'qty' => (int) (isset($row['cart_quantity']) ? $row['cart_quantity'] : 0),
                'price' => round((float) (isset($row['price']) ? $row['price'] : 0), 6),
                'price_wt' => round((float) (isset($row['price_wt']) ? $row['price_wt'] : 0), 6),
                'total' => $total,
                'total_wt' => $totalWt,
                'ecotax' => round((float) (isset($row['ecotax']) ? $row['ecotax'] : 0), 6),
                'ecotax_rate' => $ecotaxRate,
                'id_tax_rules_group' => $group,
                'declared_rate' => is_int($group) ? self::guard(function () use ($declaredRate, $group) {
                    return call_user_func($declaredRate, $group);
                }) : null,
                'implied_rate' => $total != 0.0 ? round(($totalWt - $total) / $total, 6) : null,
            );
            $out['delta'] = self::productDelta($out);
            $rows[] = $out;
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
            // What ONLY_SHIPPING is priced from: core auto-selects the best option when the stored one is empty or stale.
            'priced_option' => self::guard(function () use ($cart) {
                return $cart->getDeliveryOption(null, false);
            }),
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
        $overridden = array();
        foreach (array_keys(self::PRICE_METHODS) as $class) {
            $overridden[$class] = self::guard(function () use ($class) {
                $names = array();
                if (class_exists($class . 'Core', false) && get_parent_class($class) === $class . 'Core') {
                    foreach ((new ReflectionClass($class))->getMethods() as $method) {
                        if ($method->getDeclaringClass()->getName() === $class && method_exists($class . 'Core', $method->getName())) {
                            $names[] = $method->getName();
                        }
                    }
                }

                return array_slice($names, 0, self::MAX_ROWS);
            });
        }

        return array('files' => $files, 'methods_overridden' => $overridden);
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

    private static function cartRules(array $cartRules)
    {
        $rules = array();
        foreach ($cartRules as $rule) {
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
        foreach ($lineItems as $item) {
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

    private static function cap(array $rows, $section, array &$truncated)
    {
        if (count($rows) > self::MAX_ROWS) {
            $truncated[$section] = count($rows) - self::MAX_ROWS;
        }

        return array_slice($rows, 0, self::MAX_ROWS);
    }

    // Every row is readable and one exceeds what PS_ROUND_TYPE's rounding can explain.
    private static function hasUnexplainedProductDelta(array $snapshot)
    {
        $rows = isset($snapshot['products']) && is_array($snapshot['products']) && !isset($snapshot['products']['error'])
            ? $snapshot['products'] : array();
        $roundType = self::num($snapshot, array('config', 'PS_ROUND_TYPE'));
        $unexplained = false;
        foreach ($rows as $row) {
            $delta = is_array($row) ? self::productDelta($row) : null;
            if ($delta === null) {
                return false;
            }
            if ($roundType === 1.0) {
                // ROUND_ITEM rounds unit net and unit gross apart (Cart.php:977-983@1.7.6.5), each by up to half a cent.
                $tolerance = $row['qty'] * 0.005 * (2 + $row['declared_rate']);
            } elseif ($roundType === 2.0 || $roundType === 3.0) {
                $tolerance = 0.011;
            } else {
                return false;
            }
            $unexplained = $unexplained || abs($delta) > $tolerance;
        }

        return $unexplained;
    }

    private static function hasPriceOverrideOrHook(array $snapshot)
    {
        foreach (self::PRICE_METHODS as $class => $methods) {
            $overridden = isset($snapshot['overrides']['methods_overridden'][$class]) ? $snapshot['overrides']['methods_overridden'][$class] : null;
            if (is_array($overridden) && !isset($overridden['error']) && array_intersect($methods, $overridden)) {
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

    /**
     * The tax rules groups of the carriers in the delivery option core prices shipping from; 0 is no carrier.
     *
     * @param array $snapshot
     * @return int[]|null null when the option or any of its carriers' groups cannot be read
     */
    private static function carrierGroups(array $snapshot)
    {
        // Each key lists the option's carriers ('3,5,'), so a multi-carrier option is covered too.
        $selected = isset($snapshot['shipping']['priced_option']) ? $snapshot['shipping']['priced_option'] : null;
        if (!is_array($selected) || isset($selected['error'])) {
            return null;
        }
        $known = array();
        foreach (isset($snapshot['delivery_options']) && is_array($snapshot['delivery_options']) ? $snapshot['delivery_options'] : array() as $option) {
            foreach (isset($option['carriers']) && is_array($option['carriers']) ? $option['carriers'] : array() as $carrier) {
                $known[(int) $carrier['id_carrier']] = self::num($carrier, array('tax_rules_group'));
            }
        }
        $groups = array();
        foreach ($selected as $key) {
            if (!is_scalar($key)) {
                return null;
            }
            foreach (array_filter(explode(',', (string) $key), 'strlen') as $id) {
                // isset, not array_key_exists: a group that failed to read is null, and (int) null would pass as untaxed.
                if ((int) $id === 0) {
                    $groups[] = 0;
                } elseif (isset($known[(int) $id])) {
                    $groups[] = (int) $known[(int) $id];
                } else {
                    return null;
                }
            }
        }

        return $groups === array() ? null : array_values(array_unique($groups));
    }

    /**
     * @return float|null null when the path is missing or not a number (an {error} section)
     */
    private static function num($array, array $path)
    {
        foreach ($path as $key) {
            if (!is_array($array) || !isset($array[$key])) {
                return null;
            }
            $array = $array[$key];
        }

        return is_numeric($array) ? (float) $array : null;
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
