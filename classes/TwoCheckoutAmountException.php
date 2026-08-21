<?php
/**
 * Marker for amount-diagnostic exceptions safe to relay verbatim to the buyer
 * (cart-vs-lines reconciliation, shipping-tax-rate refusal). Payload building
 * walks PrestaShop core (DB reads, TaxManagerFactory), so an exception escaping
 * that path isn't necessarily ours — e.g. PrestaShopDatabaseException carries
 * SQL/table/column text, which would be an information disclosure if relayed.
 * Everything not of this type is logged server-side and answered generically.
 *
 * A type rather than a string convention: the order-intent path's existing
 * `stripos($msg, 'reconcile')` classifier is fragile (misses the shipping
 * refusal, misreads the PS_ATCP_SHIPWRAP split failure).
 *
 * TWO-25161.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoCheckoutAmountException extends Exception
{
}
