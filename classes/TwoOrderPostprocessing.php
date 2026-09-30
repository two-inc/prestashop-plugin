<?php
/**
 * The stable extension contract "order postprocessing" (TWO-26092): names,
 * codes and the pure payload helpers the module's choke function uses.
 *
 * Every name here is part of a permanent public contract. See README
 * "Stable extension contract: order postprocessing" before changing one.
 *
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoOrderPostprocessing
{
    const HOOK = 'actionTwoOrderPostprocessing';
    const CONTRACT_VERSION = 1;

    const REQUEST_ORDER_INTENT = 'order_intent';
    const REQUEST_ORDER_CREATE = 'order_create';
    const REQUEST_ORDER_UPDATE = 'order_update';
    const REQUEST_ORDER_CONFIRM = 'order_confirm';
    const REQUEST_CAPTURE = 'capture';
    const REQUEST_REFUND = 'refund';
    const REQUEST_CANCEL = 'cancel';

    const CODE_HOOK_FAILED = 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED';
    const CODE_BODY_NOT_ACCEPTED = 'TWO_ORDER_POSTPROCESSING_BODY_NOT_ACCEPTED';
    const CODE_LINE_INCONSISTENT = 'TWO_ORDER_POSTPROCESSING_LINE_INCONSISTENT';
    const CODE_SUBTOTALS_INCONSISTENT = 'TWO_ORDER_POSTPROCESSING_SUBTOTALS_INCONSISTENT';
    const CODE_TOTALS_INCONSISTENT = 'TWO_ORDER_POSTPROCESSING_TOTALS_INCONSISTENT';

    const MAX_STRING = 120;

    // Diff values outside these paths can carry buyer data, so only their path is kept.
    const VALUE_PATHS = '#^/(line_items|tax_subtotals)(/|$)|^/(gross_amount|net_amount|tax_amount|discount_amount|discount_rate|amount|currency)$#';

    /**
     * The payload with every object key sorted, as one string: equal strings mean an unchanged payload.
     *
     * @param mixed $payload
     * @return string
     */
    public static function canonical($payload)
    {
        $sorted = self::sortKeys($payload);
        $json = json_encode($sorted, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Invalid UTF-8 defeats JSON; serialize() still tells two payloads apart.
        return $json === false ? 'php:' . serialize($sorted) : $json;
    }

    /**
     * Leaf-level differences as JSON-pointer paths, e.g. /line_items/1/tax_amount.
     *
     * @param mixed $before
     * @param mixed $after
     * @return array<int,array{path:string,before:mixed,after:mixed}>
     */
    public static function diff($before, $after)
    {
        $out = array();
        self::diffInto($out, '', $before, $after, true, true);

        return $out;
    }

    /**
     * The diff with values outside the amount and line paths withheld, and long strings cut.
     *
     * @param array $diff
     * @return array
     */
    public static function redactDiff(array $diff)
    {
        $out = array();
        foreach ($diff as $entry) {
            $keep = preg_match(self::VALUE_PATHS, $entry['path']) === 1;
            $out[] = array(
                'path' => self::cut($entry['path']),
                'before' => $keep ? self::scalar($entry['before']) : ($entry['before'] === null ? null : 'redacted'),
                'after' => $keep ? self::scalar($entry['after']) : ($entry['after'] === null ? null : 'redacted'),
            );
        }

        return $out;
    }

    /**
     * Run each subscriber as core's Hook::exec() would, except that a throw
     * reaches the caller: core 8 and 9 discard a subscriber's Exception outside
     * debug mode, which would send a half-edited or unedited payload.
     *
     * Controller exceptions and employee permissions are not applied, so the
     * payload never depends on which page or employee triggered the request.
     *
     * @param array $payload edited in place by the subscribers
     * @param array $context
     * @return void
     * @throws Throwable whatever a subscriber threw
     */
    public static function dispatch(array &$payload, array $context)
    {
        if (defined('PS_INSTALLATION_IN_PROGRESS')
            || (method_exists('Hook', 'getHookStatusByName') && !Hook::getHookStatusByName(self::HOOK))) {
            return;
        }
        $list = Hook::getHookModuleExecList(self::HOOK);
        if (!is_array($list)) {
            return;
        }
        $nativeOnly = (bool) Configuration::get('PS_DISABLE_NON_NATIVE_MODULE');
        $native = $nativeOnly ? Module::getNativeModuleList() : array();
        $core = Context::getContext();
        $args = array('payload' => &$payload, 'context' => $context, 'cookie' => $core->cookie, 'cart' => $core->cart);
        $method = 'hook' . ucfirst(self::HOOK);
        $altern = 0;
        foreach ($list as $row) {
            $name = isset($row['module']) ? (string) $row['module'] : '';
            if ($nativeOnly && is_array($native) && count($native) && !in_array($name, $native)) {
                continue;
            }
            $module = Module::getInstanceByName($name);
            if (!$module || !$module->active || !is_callable(array($module, $method))) {
                continue;
            }
            $args['altern'] = ++$altern;
            $module->{$method}($args);
        }
    }

    /**
     * Module names registered on the hook, in execution order.
     *
     * @return string[]
     */
    public static function subscribers()
    {
        $names = array();
        $list = class_exists('Hook') ? Hook::getHookModuleExecList(self::HOOK) : false;
        foreach (is_array($list) ? $list : array() as $row) {
            if (isset($row['module'])) {
                $names[] = self::cut((string) $row['module']);
            }
        }

        return $names;
    }

    private static function sortKeys($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortKeys($item);
        }

        return $value;
    }

    private static function diffInto(array &$out, $path, $before, $after, $hasBefore, $hasAfter)
    {
        if (is_array($before) && is_array($after)) {
            $keys = array_keys($before + $after);
            foreach ($keys as $key) {
                $inBefore = array_key_exists($key, $before);
                $inAfter = array_key_exists($key, $after);
                self::diffInto(
                    $out,
                    $path . '/' . strtr((string) $key, array('~' => '~0', '/' => '~1')),
                    $inBefore ? $before[$key] : null,
                    $inAfter ? $after[$key] : null,
                    $inBefore,
                    $inAfter
                );
            }

            return;
        }
        // One side absent or not an array: report the other side's leaves.
        if (is_array($before) && $before !== array() && !$hasAfter) {
            self::diffInto($out, $path, $before, array(), true, true);

            return;
        }
        if (is_array($after) && $after !== array() && !$hasBefore) {
            self::diffInto($out, $path, array(), $after, true, true);

            return;
        }
        if ($hasBefore === $hasAfter && self::canonical($before) === self::canonical($after)) {
            return;
        }
        $out[] = array(
            'path' => $path === '' ? '/' : $path,
            'before' => $hasBefore ? $before : null,
            'after' => $hasAfter ? $after : null,
        );
    }

    private static function scalar($value)
    {
        if (is_string($value)) {
            return self::cut($value);
        }
        if (is_array($value) || is_object($value)) {
            return self::cut(self::canonical($value));
        }

        return $value;
    }

    private static function cut($value)
    {
        $value = (string) $value;
        if (strlen($value) <= self::MAX_STRING) {
            return $value;
        }

        return (function_exists('mb_strcut') ? mb_strcut($value, 0, self::MAX_STRING, 'UTF-8') : substr($value, 0, self::MAX_STRING)) . '...';
    }
}
