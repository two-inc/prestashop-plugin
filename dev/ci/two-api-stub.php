<?php
/**
 * Router for a throwaway `php -S` stand-in for Two's API, run INSIDE the
 * PrestaShop container by dev/ci/start-two-api-stub.sh (TWO-25326).
 *
 * Why it exists: the module now withholds the payment option (and the
 * company-search control) whenever the stored API key cannot be verified -
 * including when the API cannot be reached at all, which is exactly what the
 * e2e harness deliberately arranged by pointing TWO_API_BASE_URL at a dead
 * port. That posture is correct in production and fatal to a UI suite whose
 * whole subject is the payment tile, so the harness needs *something* to
 * answer the verification call. It must not be Two's real sandbox: this repo
 * is public and its CI carries no secrets.
 *
 * Answers the verification endpoint, the merchant record and, for the
 * pending-payment recovery spec, an order store (see ORDERS below). Every other path keeps
 * the previous fail-fast behaviour (the checkout-media priming calls for
 * merchant terms and FX rates are expected to fail in this harness and are
 * documented as such in seed-two-config.sh) - so this stub widens the
 * hermetic surface by exactly one endpoint rather than becoming a general
 * fake of the API, which would start silently deciding e2e outcomes.
 *
 * The merchant identity returned matches what seed-two-config.sh writes into
 * Configuration, so the two cannot disagree.
 *
 * Also answers GET /v1/merchant/{id} (TWO-25503): getMerchantAvailableTerms()
 * withholds the payment option entirely when this fetch has never populated
 * the available_terms cache, so the e2e suite needs a real term list from the
 * wire rather than a Configuration value seeded around the fetch. No
 * min_order_* fields, matching seed-two-config.sh's "no platform minimum"
 * posture for this harness.
 *
 * WHAT THIS STUB STOPS THE SUITE FROM COVERING. It answers 200 for ANY API key
 * and ignores the X-API-Key header entirely, so the e2e suite can no longer
 * catch a regression in how the key is sent, nor anything in the REJECT
 * direction. That is deliberate - the suite's subject is checkout UI, and a
 * secret-free CI cannot exercise a real credential either way - but it means the
 * category logic and the header contract are pinned by the PHP suite
 * (tests/ApiKeyVerificationSpec.php) and by nothing here.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/*
 * BUYER FEE QUOTE (TWO-26300). The recovery spec's button case places a cart carrying the
 * payment-terms fee line, and the module quotes that fee from Two before it builds the order.
 * The answer is the configured share of the gross: percentage, plus any fixed amount, under any
 * cap. It is not Two's pricing; it only has to answer the same way every time it is asked.
 */
if ($path === '/v1/pricing/order/fee' && $method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $share = isset($body['buyer_fee_share']) && is_array($body['buyer_fee_share']) ? $body['buyer_fee_share'] : array();
    $gross = isset($body['gross_amount']) ? (float) $body['gross_amount'] : 0.0;
    $fee = $gross * (isset($share['percentage']) ? (float) $share['percentage'] : 0.0) / 100;
    if (isset($share['fixed'])) {
        $fee += is_array($share['fixed']) ? (float) (isset($share['fixed']['amount']) ? $share['fixed']['amount'] : 0) : (float) $share['fixed'];
    }
    if (isset($share['cap'])) {
        $cap = is_array($share['cap']) ? (float) (isset($share['cap']['amount']) ? $share['cap']['amount'] : 0) : (float) $share['cap'];
        $fee = min($fee, $cap);
    }
    header('Content-Type: application/json');
    echo json_encode(array(
        'buyer_fee_share' => number_format($fee, 2, '.', ''),
        'currency' => isset($body['currency']) ? $body['currency'] : 'EUR',
    ));
    return true;
}

/*
 * ORDERS (TWO-26300). The pending-payment recovery spec needs a Two order whose
 * state it controls, so the stub also keeps orders: POST /v1/order creates one
 * UNVERIFIED, GET and PUT /v1/order/{id} read and update it, and
 * POST /v1/order/{id}/confirm and /cancel move it. State lives in a JSON file
 * under flock, because php -S runs several workers. The spec drives it through
 * two controls that are not Two endpoints:
 *   POST /_stub/order/{id}/state  body {"state": "...", "status": "..."}
 *   GET  /_stub/requests?order={id}  every order request seen, in order
 * Nothing here decides an outcome on its own: an order changes state only when
 * the module asks it to or the spec sets it.
 */
if ($path === '/v1/order' || strpos($path, '/v1/order/') === 0 || strpos($path, '/_stub/') === 0) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : array();
    $answer = two_stub_with_state(function (array &$state) use ($path, $method, $body) {
        $state['requests'][] = array('method' => $method, 'path' => $path);
        if ($path === '/v1/order' && $method === 'POST') {
            $id = 'e2e-order-' . bin2hex(random_bytes(6));
            $state['orders'][$id] = array(
                'id' => $id,
                'state' => 'UNVERIFIED',
                'status' => 'APPROVED',
                'merchant_reference' => isset($body['merchant_reference']) ? $body['merchant_reference'] : '',
                'merchant_order_id' => isset($body['merchant_order_id']) ? $body['merchant_order_id'] : '',
                'currency' => isset($body['currency']) ? $body['currency'] : 'EUR',
                'gross_amount' => isset($body['gross_amount']) ? $body['gross_amount'] : '0',
                'net_amount' => isset($body['net_amount']) ? $body['net_amount'] : '0',
                'tax_amount' => isset($body['tax_amount']) ? $body['tax_amount'] : '0',
                'line_items' => isset($body['line_items']) ? $body['line_items'] : array(),
                'payment_url' => 'http://127.0.0.1/_stub/pay/' . $id,
                'invoice_url' => '',
            );

            return array(201, $state['orders'][$id]);
        }
        if ($path === '/_stub/requests') {
            $order = isset($_GET['order']) ? (string) $_GET['order'] : '';

            return array(200, array_values(array_filter($state['requests'], function ($r) use ($order) {
                return strpos($r['path'], '/_stub/') !== 0 && ($order === '' || strpos($r['path'], '/' . $order) !== false);
            })));
        }
        if (!preg_match('#^/(?:v1|_stub)/order/([A-Za-z0-9_-]+)(?:/([a-z_]+))?$#', $path, $m)) {
            return array(404, array('error_code' => 'NOT_FOUND'));
        }
        $id = $m[1];
        $action = isset($m[2]) ? $m[2] : '';
        if (!isset($state['orders'][$id])) {
            return array(404, array('error_code' => 'ORDER_NOT_FOUND', 'error_message' => 'Order not found'));
        }
        $order = &$state['orders'][$id];
        if (strpos($path, '/_stub/') === 0 && $action === 'state') {
            $order['state'] = isset($body['state']) ? (string) $body['state'] : $order['state'];
            $order['status'] = isset($body['status']) ? (string) $body['status'] : $order['status'];

            return array(200, $order);
        }
        if ($action === '' && $method === 'GET') {
            return array(200, $order);
        }
        if ($action === '' && $method === 'PUT') {
            foreach (array('merchant_order_id', 'merchant_reference', 'gross_amount', 'net_amount', 'tax_amount', 'line_items') as $key) {
                if (isset($body[$key])) {
                    $order[$key] = $body[$key];
                }
            }

            return array(200, $order);
        }
        if ($action === 'confirm' && $method === 'POST') {
            if ($order['state'] !== 'VERIFIED' && $order['state'] !== 'CONFIRMED') {
                return array(400, array('error_code' => 'INVALID_STATE', 'error_message' => 'Order is ' . $order['state']));
            }
            $order['state'] = 'CONFIRMED';

            return array(200, $order);
        }
        if ($action === 'cancel' && $method === 'POST') {
            $order['state'] = 'CANCELLED';

            return array(200, $order);
        }

        return array(404, array('error_code' => 'NOT_FOUND'));
    });
    http_response_code($answer[0]);
    header('Content-Type: application/json');
    echo json_encode($answer[1]);
    return true;
}

if ($path === '/v1/merchant/verify_api_key') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'id' => 'e2e-merchant-id',
        'short_name' => 'E2E Test Merchant',
    ));
    return true;
}

if ($path === '/v1/merchant/e2e-merchant-id') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'id' => 'e2e-merchant-id',
        'short_name' => 'E2E Test Merchant',
        'available_terms' => array(14, 30),
        'due_in_days' => 14,
        'invoice_distributed_by_merchant' => false,
    ));
    return true;
}

// Anything else: refuse, promptly. NOT identical to the dead port this replaced,
// and the difference is worth stating: a dead port produced a cURL TRANSPORT
// failure, while this produces an HTTP 503. The module distinguishes the two
// (transport -> 'unreachable', 5xx -> 'service_error'), so the e2e suite's
// "declined gracefully" assertion now travels the 5xx branch rather than the
// transport one. Both are decline paths and the assertion is about the decline
// being graceful, not about which branch produced it; the branch-level
// distinction is covered by tests/ApiKeyVerificationSpec.php, which drives every
// category directly.
http_response_code(503);
header('Content-Type: application/json');
echo json_encode(array('error' => 'not stubbed'));
return true;

/**
 * Run $fn on the stub's order state under an exclusive lock and write it back.
 *
 * @param callable $fn
 * @return array{0:int,1:mixed}
 */
function two_stub_with_state(callable $fn)
{
    $handle = fopen('/tmp/two-api-stub-state.json', 'c+');
    flock($handle, LOCK_EX);
    $state = json_decode((string) stream_get_contents($handle), true);
    if (!is_array($state)) {
        $state = array('orders' => array(), 'requests' => array());
    }
    $answer = $fn($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $answer;
}
