<?php
/**
 * The plugin's own buyer-actionable amount diagnostic.
 *
 * Payload building walks a lot of PrestaShop core (TaxManagerFactory, Address,
 * Carrier, DB reads), so an exception escaping that path is NOT necessarily
 * something the plugin wrote: a PrestaShopDatabaseException would carry SQL
 * text, table and column names. Relaying an arbitrary exception message to the
 * buyer — into the storefront notification and, since TWO-24768, into the AJAX
 * JSON body — is an information disclosure on a public storefront.
 *
 * This type is the marker for the narrow set of conditions where the message IS
 * meant for the buyer: the cart-vs-lines reconciliation diagnostics and the
 * loud shipping-tax-rate refusal, all of which quote nothing but the cart's own
 * amounts and identifiers. Everything else is logged server-side and answered
 * with a generic message.
 *
 * Deliberately a type rather than a string convention: the order-intent path
 * already classifies these failures with `stripos($msg, 'reconcile')`, which is
 * a fragile classifier (it misses the shipping refusal and misreads the
 * PS_ATCP_SHIPWRAP split failure), and a second string test would compound that
 * rather than replace it.
 *
 * TWO-25161.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoCheckoutAmountException extends Exception
{
}
