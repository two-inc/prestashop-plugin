<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * The one normalisation of a stored custom payment term, shared by the admin
 * field's visibility gate, its renderer and every reader of the stored value -
 * two readings would disagree on a hand-edited row and one of them would then
 * delete it (ABN-522).
 */
class TwoStoredTerm
{
    /**
     * Nothing worth showing: absent, empty, or a zero, which is not a term and reads as blank.
     *
     * @param mixed $configured
     * @return bool
     */
    public static function isBlank($configured)
    {
        if (!is_scalar($configured)) {
            return true;
        }
        $trimmed = trim((string) $configured);

        return $trimmed === '' || preg_match('/^0+$/', $trimmed) === 1;
    }

    /**
     * The term the value denotes, or null where it denotes none.
     *
     * @param mixed $configured
     * @return int|null
     */
    public static function days($configured)
    {
        if (!is_scalar($configured)) {
            return null;
        }
        $trimmed = trim((string) $configured);

        return preg_match('/^\d+$/', $trimmed) === 1 && (int) $trimmed > 0 ? (int) $trimmed : null;
    }

    /**
     * Stored but not a number of days. It has to stay visible and block the save:
     * hiding it would leave the merchant no way to correct it.
     *
     * @param mixed $configured
     * @return bool
     */
    public static function isUnusable($configured)
    {
        return !self::isBlank($configured) && self::days($configured) === null;
    }
}
