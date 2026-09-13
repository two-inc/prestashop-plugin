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
        self::testEscapingIsIdempotent();
        self::testAnEmptyAttributeValueRaisesNoWarning();
        self::testRendersUnchangedIgnoresEntityEncodingOnly();
    }

    private static function testOnlyTheLinkThisModuleEmitsSurvives(): void
    {
        foreach (self::cases() as list($input, $expected, $description)) {
            TinyAssert::same($expected, TwoAnchorOnlyHtml::escape($input), $description);
        }
    }

    /** @return array<int, array{0:string,1:string,2:string}> [input, escaped output, why] */
    private static function cases(): array
    {
        $url = 'https://faq.example.test/x';

        return [
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
            ['<a href="javascript:x=\'https://ok.example\'">read more</a>', 'read more', 'a script URL carrying https: later in the string is still not an http(s) target'],
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
            ['<a href="http://faq.example.test/x">read more</a>', '<a href="http://faq.example.test/x">read more</a>', 'plain http is a reachable page, not only https'],
            ['<A HREF="' . $url . '">read more</A>', '<a href="' . $url . '">read more</a>', 'an uppercase tag is markup too, not text'],
            ['<a href="  ' . $url . '  ">read more</a>', '<a href="' . $url . '">read more</a>', 'padding a stored href does not change the target'],
            ['<a href="&#106;avascript:alert(1)">read more</a>', 'read more', 'entity-encoding a script URL does not smuggle it past the scheme test'],
            ['Tea &amp; coffee & cake', 'Tea &amp; coffee &amp; cake', 'an entity already in the copy is left alone while a bare ampersand is escaped'],
            [
                '<a href="https://faq.example.test/x?a=1&amp;b=2">read more</a>',
                '<a href="https://faq.example.test/x?a=1&amp;b=2">read more</a>',
                'a two-parameter query string survives one decode and one re-encode unchanged',
            ],
            [
                "<a href='https://faq.example.test/x?q=\"z\"'>read more</a>",
                '<a href="https://faq.example.test/x?q=&quot;z&quot;">read more</a>',
                'a quote inside the href is encoded rather than closing the attribute',
            ],
            ['<a href="">read more</a>', 'read more', 'an empty href is no link'],
            ["<a href=''>read more</a>", 'read more', 'nor is an empty single-quoted one'],
            ['<a href="https://user:pw@evil.example.test">read more</a>', 'read more', 'userinfo lets the text before the @ pose as the host, so the link is dropped'],
            [
                '<a href="https://faq.example.test/x?to=a@b">read more</a>',
                '<a href="https://faq.example.test/x?to=a@b">read more</a>',
                'an @ past the authority is ordinary query text',
            ],
            [
                '<a href="https://faq.example.test?to=a@b">read more</a>',
                '<a href="https://faq.example.test?to=a@b">read more</a>',
                'a query opening straight off the authority ends it, so the @ after it is not userinfo',
            ],
            [
                '<a href="https://faq.example.test#@b">read more</a>',
                '<a href="https://faq.example.test#@b">read more</a>',
                'a fragment ends the authority the same way',
            ],
            [
                '<a href="' . $url . '" target="_BLANK" rel="NOOPENER">read more</a>',
                '<a href="' . $url . '" target="_blank" rel="noopener">read more</a>',
                'browsers read these keywords case-insensitively, so they are matched that way and re-emitted lowercased',
            ],
            [
                '<a href="' . $url . '" target="_blank">read more</a>',
                '<a href="' . $url . '" target="_blank" rel="noopener">read more</a>',
                'a new-tab link gets noopener whether or not the copy asked for it',
            ],
            [
                '<a href="' . $url . '" rel="noopener noreferrer">read more</a>',
                '<a href="' . $url . '" rel="noopener">read more</a>',
                'rel is read as a token set, so writing the stricter pair does not cost the link its noopener',
            ],
            [
                '<a href="' . $url . '" rel="NOOPENER">read more</a>',
                '<a href="' . $url . '" rel="noopener">read more</a>',
                'rel is matched case-insensitively even with no target to pair it with',
            ],
            ["caf\xC3\xA9 \xC0\xAF costs \xE2\x82\xAC5", "caf\u{00E9} \u{FFFD}\u{FFFD} costs \u{20AC}5", 'one malformed byte is substituted, not allowed to blank the whole run'],
            ["safe\x00ish", 'safeish', 'a control character cannot render and is dropped'],
            [
                'Pay in 30 days < see <a href="' . $url . '">terms</a>',
                'Pay in 30 days &lt; see <a href="' . $url . '">terms</a>',
                'a stray < is text and does not swallow the copy up to the next >',
            ],
            [
                '<a href="HTTPS://faq.example.test/x">read more</a>',
                '<a href="HTTPS://faq.example.test/x">read more</a>',
                'schemes are case-insensitive to a browser, so an uppercase one is still a reachable page',
            ],
            [
                '<a href="' . $url . '">read more</a  > and on',
                '<a href="' . $url . '">read more</a> and on',
                'a close tag padded before its bracket still closes the link, rather than letting it swallow the rest',
            ],
            [
                '<a href="javascript:alert(1)" href="' . $url . '">read more</a>',
                'read more',
                'a browser reads the first href, so a duplicate cannot hide a script URL behind a safe one',
            ],
            [
                '<a href="' . $url . '" rel="nofollow" rel="noopener">read more</a>',
                '<a href="' . $url . '">read more</a>',
                'the first rel is the one a browser reads, so a duplicate cannot smuggle a keyword in',
            ],
            [
                '<abbr href="' . $url . '">read more</abbr>',
                'read more',
                'only the anchor element is an anchor: a tag merely starting with an a is dropped like any other',
            ],
        ];
    }

    /**
     * The subtitle is re-escaped on every render, so a second pass has to be a
     * no-op - otherwise each render would re-encode the last one's entities.
     */
    private static function testEscapingIsIdempotent(): void
    {
        foreach (self::cases() as list($input, $expected, $description)) {
            TinyAssert::same($expected, TwoAnchorOnlyHtml::escape($expected), 'escaping twice changes the output: ' . $description);
        }
    }

    /**
     * An empty quoted value leaves its capture group absent, and the resulting
     * notice would be written into the middle of the checkout markup on a shop
     * with display_errors on.
     */
    /**
     * The admin save gate (ABN-554). A value is rejected exactly when escaping
     * would change what the buyer sees - entity-encoding plain text is not a
     * change, so apostrophes and ampersands are not markup.
     */
    private static function testRendersUnchangedIgnoresEntityEncodingOnly(): void
    {
        $url = 'https://faq.example.test/x';

        $cases = [
            ['', true, 'an empty subtitle is nothing to strip'],
            ['Pay later, interest free', true, 'plain copy renders verbatim'],
            ["Don't wait & save", true, 'an apostrophe and an ampersand are text the escaper only encodes'],
            ['2 < 3', true, 'a stray < is encoded as text, not treated as markup'],
            ['Tea &amp; coffee', true, 'copy that already carries an entity is left alone'],
            ['<a href="' . $url . '">read more</a>', true, 'the anchor the tile allows survives verbatim'],
            [
                '<a href="' . $url . '" target="_blank" rel="noopener">read more</a>',
                true,
                'so does the new-tab form this module itself emits',
            ],
            ['<b>Pay</b> later', false, 'a dropped tag changes what the buyer reads'],
            ['<a href="javascript:alert(1)">read more</a>', false, 'a link whose target is refused loses its anchor'],
            [
                '<a href="' . $url . '" target="_blank">read more</a>',
                false,
                'a new-tab link is rewritten to carry noopener, so it is not what was typed',
            ],
            ['<a href="' . $url . '" onclick="steal()">read more</a>', false, 'a dropped attribute is a change too'],
            ["safe\x00ish", false, 'a control character is removed'],
        ];

        foreach ($cases as list($input, $expected, $description)) {
            TinyAssert::same($expected, TwoAnchorOnlyHtml::rendersUnchanged($input), $description);
        }
    }

    private static function testAnEmptyAttributeValueRaisesNoWarning(): void
    {
        $raised = [];
        set_error_handler(static function ($severity, $message) use (&$raised) {
            $raised[] = $message;

            return true;
        });
        try {
            TwoAnchorOnlyHtml::escape('<a href="" target="" rel="">read more</a>');
        } finally {
            restore_error_handler();
        }

        TinyAssert::same([], $raised, 'escaping an empty attribute value raised: ' . implode('; ', $raised));
    }
}
