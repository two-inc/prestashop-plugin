<?php

declare(strict_types=1);

/**
 * TWO-25386 brand-token mechanism: brands/two.php declares 'product_name',
 * getTwoBrandConfig('product_name') resolves it, and admin/checkout captions
 * that used to hardcode the literal word "Two" now interpolate it instead.
 *
 * This does not re-sweep every converted caption (that is what
 * TranslationCatalogueSpec's extraction floor and the sprintf-token parity
 * check guard structurally); it proves the seam itself resolves, and spot
 * checks a representative caption from each shape used in the sweep:
 * single-mention (%s), repeated-mention (%1$s), and a %d-carrying message
 * where the brand token must not disturb the existing conversion.
 */
final class BrandTokenSpec
{
    public static function runAll(): void
    {
        self::testProductNameResolvesToTwoByDefault();
        self::testUnknownBrandKeyStillResolvesToNull();
        self::testSingleMentionCaptionInterpolatesProductName();
        self::testRepeatedMentionCaptionReusesTheSameProductName();
        self::testHttpCodeCaptionKeepsBothTokensInOrder();
    }

    private static function testProductNameResolvesToTwoByDefault(): void
    {
        $module = new TwopaymentTestHarness();
        TinyAssert::same('Two', $module->getTwoBrandConfig('product_name'));
    }

    /**
     * getTwoBrandConfig() is documented to return null for a key brands/two.php
     * never declares (TWO-24746's minimal seam) - 'product_name' being added
     * must not have turned that into some other fallback.
     */
    private static function testUnknownBrandKeyStillResolvesToNull(): void
    {
        $module = new TwopaymentTestHarness();
        TinyAssert::same(null, $module->getTwoBrandConfig('does_not_exist_in_brands_two_php'));
    }

    /**
     * getTwoApiKeyFailureMessage(API_KEY_STATUS_NOT_CONFIGURED) used to be the
     * literal 'Enter your Two API key to enable Two.' - now it's built from
     * sprintf() + getTwoBrandConfig('product_name') with the SAME word
     * mentioned twice via %1$s, and must render byte-identical to the old
     * hardcoded English default.
     */
    private static function testRepeatedMentionCaptionReusesTheSameProductName(): void
    {
        $module = new TwopaymentTestHarness();
        TinyAssert::same(
            'Enter your Two API key to enable Two.',
            $module->getTwoApiKeyFailureMessage(Twopayment::API_KEY_STATUS_NOT_CONFIGURED)
        );
    }

    /**
     * API_KEY_STATUS_UNREACHABLE carries exactly one brand mention (%s).
     */
    private static function testSingleMentionCaptionInterpolatesProductName(): void
    {
        $module = new TwopaymentTestHarness();
        TinyAssert::same(
            'This shop could not reach the Two API at all (network, DNS or firewall). '
                . 'The API key itself has not been judged.',
            $module->getTwoApiKeyFailureMessage(Twopayment::API_KEY_STATUS_UNREACHABLE)
        );
    }

    /**
     * API_KEY_STATUS_SERVICE_ERROR carries a brand token AND a %d HTTP code -
     * the sweep added the brand argument without disturbing the pre-existing
     * %d substitution or its position relative to the code value passed in.
     */
    private static function testHttpCodeCaptionKeepsBothTokensInOrder(): void
    {
        $module = new TwopaymentTestHarness();
        TinyAssert::same(
            'Two could not verify the API key right now (HTTP 503). This is usually temporary - try again shortly.',
            $module->getTwoApiKeyFailureMessage(Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503)
        );
    }
}
