<?php
/**
 * "The stored surcharge method is not one this module can price."
 *
 * A type rather than a message comparison, so rewording or translating the
 * refusal cannot turn the quiet degrade path into an error-logging one.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoSurchargeMethodException extends Exception
{
}
