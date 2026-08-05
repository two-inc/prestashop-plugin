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
 * Deliberately answers ONLY the verification endpoint. Every other path keeps
 * the previous fail-fast behaviour (the checkout-media priming calls for
 * merchant terms and FX rates are expected to fail in this harness and are
 * documented as such in seed-two-config.sh) - so this stub widens the
 * hermetic surface by exactly one endpoint rather than becoming a general
 * fake of the API, which would start silently deciding e2e outcomes.
 *
 * The merchant identity returned matches what seed-two-config.sh writes into
 * Configuration, so the two cannot disagree.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/v1/merchant/verify_api_key') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'id' => 'e2e-merchant-id',
        'short_name' => 'E2E Test Merchant',
    ));
    return true;
}

// Anything else: refuse, promptly. Same outcome the dead port produced.
http_response_code(503);
header('Content-Type: application/json');
echo json_encode(array('error' => 'not stubbed'));
return true;
