<?php

declare(strict_types=1);

namespace PrestaShop\Autoload;

/**
 * The class index generator PrestaShop 8.2 moved to and 9 kept as the only one
 * (TWO-25265). Loaded part-way through OverrideReinstallSpec rather than from
 * tests/bootstrap.php: its mere existence is what selects that branch, and a
 * class cannot be undefined again once loaded.
 */
class PrestashopAutoload
{
    /** @var int */
    public static $generateIndexCalls = 0;

    /** @return self */
    public static function getInstance()
    {
        return new self();
    }

    /** @return void */
    public function generateIndex()
    {
        ++self::$generateIndexCalls;
    }
}
