<?php
/**
 * Marker for "the stored surcharge method is not one this module can price".
 *
 * A type rather than a message comparison, for the same reason
 * TwoCheckoutAmountException is one: the quiet path must not turn into an
 * error-logging path because someone reworded or translated the string.
 * getTwoSurchargeSettings() reports the offending value once; callers that
 * degrade on this condition therefore stay silent, and anything else escaping
 * the same read is logged.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoSurchargeMethodException extends Exception
{
}
