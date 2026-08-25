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
