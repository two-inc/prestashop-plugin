<?php

declare(strict_types=1);

/**
 * The payment tile emits the subtitle unescaped, so TwoAnchorOnlyHtml is the
 * whole trust boundary between brand/merchant copy and the buyer's page.
 */
final class TwoAnchorOnlyHtmlSpec
{
    public static function runAll(): void
    {
        self::testOnlyTheLinkThisModuleEmitsSurvives();
    }

    private static function testOnlyTheLinkThisModuleEmitsSurvives(): void
    {
        $url = 'https://faq.example.test/x';

        // [input, escaped output, why].
        $cases = [
            ['For all companies, read more.', 'For all companies, read more.', 'copy with no markup is untouched'],
            [
                'For all companies, <a href="' . $url . '" target="_blank" rel="noopener">read more</a>.',
                'For all companies, <a href="' . $url . '" target="_blank" rel="noopener">read more</a>.',
                'the anchor this module emits survives verbatim',
            ],
            ['<a href="' . $url . '" onclick="steal()">read more</a>', '<a href="' . $url . '">read more</a>', 'an event handler never reaches the page'],
            ['<a href="' . $url . '" style="position:fixed;inset:0">read more</a>', '<a href="' . $url . '">read more</a>', 'styling cannot turn the link into an overlay'],
            ['<a href="' . $url . '" class="btn">read more</a>', '<a href="' . $url . '">read more</a>', "copy cannot borrow the theme's classes"],
            ['<a href="' . $url . '" download="invoice.pdf">read more</a>', '<a href="' . $url . '">read more</a>', 'the link cannot be turned into a download'],
            ['<a href="' . $url . '" target="_top">read more</a>', '<a href="' . $url . '">read more</a>', 'only the _blank this module emits is kept'],
            ['<a href="' . $url . '" rel="me">read more</a>', '<a href="' . $url . '">read more</a>', 'only the noopener this module emits is kept'],
            ['<a href="javascript:alert(1)">read more</a>', 'read more', 'a script URL loses the anchor and keeps the text'],
            ['<a href="data:text/html,pwned">read more</a>', 'read more', 'a data URL loses the anchor and keeps the text'],
            ['<b>Bold</b> and <span style="x">span</span>', 'Bold and span', 'every non-anchor tag is dropped and its text kept'],
            ['<a href="' . $url . '">read more', '<a href="' . $url . '">read more</a>', 'an anchor left open is closed rather than swallowing the page'],
            ['read <a href="' . $url . '"', 'read &lt;a href=&quot;' . $url . '&quot;', 'a tag with no closing bracket is text, not markup'],
            ['<script>alert(1)</script>', 'alert(1)', 'a script element is reduced to inert text'],
            [
                '<a href="https://a.example.test/1">outer <a href="https://b.example.test/2">inner</a> tail</a>',
                '<a href="https://a.example.test/1">outer inner</a> tail',
                'a nested anchor loses its tag, not its text',
            ],
        ];

        foreach ($cases as list($input, $expected, $description)) {
            TinyAssert::same($expected, TwoAnchorOnlyHtml::escape($input), $description);
        }
    }
}
