<?php
/**
 * An order request the order postprocessing hook failed (TWO-26092): a
 * subscriber threw, or left something that is not a JSON-encodable array.
 *
 * Deliberately NOT a TwoCheckoutAmountException: the buyer gets the plugin's
 * existing generic refusal, and the code goes to the merchant log.
 *
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoOrderPostprocessingException extends Exception
{
    /** @var string */
    private $twoCode;

    /**
     * @param string $twoCode one of the TwoOrderPostprocessing::CODE_* values
     * @param string $detail
     * @param Throwable|null $previous
     */
    public function __construct($twoCode, $detail, $previous = null)
    {
        $this->twoCode = (string) $twoCode;
        parent::__construct($this->twoCode . ': ' . $detail, 0, $previous);
    }

    /**
     * @return string
     */
    public function getTwoCode()
    {
        return $this->twoCode;
    }
}
