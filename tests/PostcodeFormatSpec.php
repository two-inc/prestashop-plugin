<?php

declare(strict_types=1);

/**
 * TWO-26257 - company-details postcodes are fitted to the country's
 * zip_code_format before the checkout copies them into the address form,
 * because core's Country::checkZipCode() refuses "6235EB" for NL ("NNNN LL").
 */
final class PostcodeFormatSpec
{
    /** zip_code_format values as core ships them. */
    private const FORMATS = ['NL' => 'NNNN LL', 'PL' => 'NN-NNN', 'PT' => 'NNNN-NNN', 'LV' => 'C-NNNN', 'IE' => ''];

    public static function runAll(): void
    {
        self::testFormat();
        self::testEveryAddressIsFittedToItsOwnCountry();
    }

    private static function testFormat(): void
    {
        foreach (self::cases() as list($iso, $input, $expected, $description)) {
            $actual = TwoPostcodeFormat::format($input, self::FORMATS[$iso], $iso);
            TinyAssert::same($expected, $actual, $description . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
            TinyAssert::true(self::checkZipCode($actual, self::FORMATS[$iso], $iso) || $actual === $input, $description . ': a rewrite always passes core');
        }
    }

    /** @return array<int, array{0:string,1:string,2:string,3:string}> [iso, postcode, expected, why] */
    private static function cases(): array
    {
        return [
            ['NL', '6235EB', '6235 EB', 'NL without its space gets it back'],
            ['NL', '6235 EB', '6235 EB', 'NL already in format is unchanged'],
            ['NL', '6235-EB', '6235 EB', 'a hyphen is a separator too'],
            ['NL', '6235eb', '6235 eb', 'lower-case letters keep their case, which core accepts'],
            ['PL', '00950', '00-950', 'PL gets its hyphen'],
            ['PT', '1000001', '1000-001', 'PT gets its hyphen'],
            ['LV', 'lv1050', 'lv-1050', 'C matches the iso code case-insensitively, as core does'],
            ['LV', '1050', '1050', 'a missing iso prefix is not invented'],
            ['NL', '62355EB', '62355EB', 'too many characters is left untouched'],
            ['NL', '6235E', '6235E', 'too few characters is left untouched'],
            ['NL', 'EB6235', 'EB6235', 'letters in digit slots is left untouched'],
            ['IE', 'D02X285', 'D02X285', 'a country with no format is untouched'],
            ['NL', '', '', 'an empty postcode stays empty'],
        ];
    }

    private static function testEveryAddressIsFittedToItsOwnCountry(): void
    {
        $body = [
            'country' => 'NL',
            'addresses' => [
                ['postal_code' => '6235EB', 'country' => 'NL'],
                ['postal_code' => '00950', 'country' => 'pl'],
                ['postal_code' => '6235EB'],
                ['postal_code' => null, 'country' => 'NL'],
                ['postal_code' => '6235EB', 'country' => 'XX'],
            ],
        ];
        $asked = [];
        $out = TwoPostcodeFormat::formatCompanyAddresses($body, function ($iso) use (&$asked) {
            $asked[] = $iso;

            return self::FORMATS[$iso] ?? '';
        });

        TinyAssert::same('6235 EB', $out['addresses'][0]['postal_code'], 'an address in its own country');
        TinyAssert::same('00-950', $out['addresses'][1]['postal_code'], 'a lower-case address country is upper-cased for the lookup');
        TinyAssert::same('6235 EB', $out['addresses'][2]['postal_code'], 'an address with no country takes the company country');
        TinyAssert::same(null, $out['addresses'][3]['postal_code'], 'a null postcode is left alone');
        TinyAssert::same('6235EB', $out['addresses'][4]['postal_code'], 'a country the shop has no format for is untouched');
        TinyAssert::same(['NL', 'PL', 'NL', 'XX'], $asked, 'the format is looked up per address country');
        TinyAssert::same(['name' => 'x'], TwoPostcodeFormat::formatCompanyAddresses(['name' => 'x'], fn () => 'NNNN LL'), 'a body with no addresses is unchanged');
    }

    /** Country::checkZipCode() as PrestaShop 1.7.6, 8 and 9 implement it. */
    private static function checkZipCode(string $zip, string $format, string $iso): bool
    {
        if ($format === '') {
            return true;
        }
        $re = str_replace(['N', 'L', 'C'], ['[0-9]', '[a-zA-Z]', $iso], '/^' . $format . '$/ui');

        return (bool) preg_match($re, $zip);
    }
}
