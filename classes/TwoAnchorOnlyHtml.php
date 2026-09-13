<?php

/**
 * Reduces buyer-facing copy to text plus links: an `<a>` with an http(s) href
 * survives, every other tag is dropped and its text kept, and all other markup
 * is escaped.
 *
 * Surviving anchors are rebuilt from their allowed attributes, so no attribute
 * this module does not itself emit can reach the page. The href itself is only
 * checked for scheme and userinfo, not vouched for - whoever writes the copy
 * chooses where an http(s) link points. `target` and `rel` are matched
 * case-insensitively, as browsers treat those keywords; `rel` is read as a
 * token set, and a kept `target="_blank"` always carries `rel="noopener"`.
 */
class TwoAnchorOnlyHtml
{
    /**
     * @param mixed $html
     * @return string
     */
    public static function escape($html)
    {
        // Only a name-like tag opens markup; a stray '<' stays text rather than
        // swallowing the copy up to the next '>'.
        $parts = preg_split('/(<\/?[a-zA-Z][^>]*>)/', self::stripControlCharacters((string) $html), -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return '';
        }

        $result = '';
        $openAnchors = 0;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $result .= self::escapeText($part);
                continue;
            }

            if (preg_match('/^<\/a\s*>$/i', $part)) {
                if ($openAnchors > 0) {
                    $result .= '</a>';
                    $openAnchors--;
                }
                continue;
            }

            // A nested anchor is invalid HTML the browser would unnest anyway;
            // its text is kept, its tag is not.
            if ($openAnchors === 0 && preg_match('/^<a\s[^>]*>$/i', $part)) {
                $anchor = self::rebuildAnchor($part);
                if ($anchor !== '') {
                    $result .= $anchor;
                    $openAnchors++;
                }
            }
        }

        return $result . str_repeat('</a>', $openAnchors);
    }

    /**
     * @param string $text
     * @return string
     */
    private static function escapeText($text)
    {
        // ENT_SUBSTITUTE: without it one malformed byte blanks the whole run.
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /**
     * @param string $text
     * @return string
     */
    private static function stripControlCharacters($text)
    {
        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        return $stripped === null ? '' : $stripped;
    }

    /**
     * @param string $tag
     * @return string the anchor rebuilt from its allowed attributes, or ''
     *                when the href is not a plain http(s) URL
     */
    private static function rebuildAnchor($tag)
    {
        $attributes = self::attributes($tag);
        $href = html_entity_decode(trim(isset($attributes['href']) ? $attributes['href'] : ''), ENT_QUOTES, 'UTF-8');
        if (!preg_match('/^https?:\/\//i', $href)) {
            return '';
        }
        // Userinfo is the classic spoof: everything before the '@' reads as the host.
        if (preg_match('/^https?:\/\/[^\/?#]*@/i', $href)) {
            return '';
        }

        $opensNewTab = isset($attributes['target']) && strtolower(trim($attributes['target'])) === '_blank';
        $relTokens = preg_split('/\s+/', isset($attributes['rel']) ? strtolower(trim($attributes['rel'])) : '', -1, PREG_SPLIT_NO_EMPTY);

        $anchor = '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if ($opensNewTab) {
            $anchor .= ' target="_blank"';
        }
        // A new tab without noopener hands the opener over, so the pair is not the copy's to split.
        if ($opensNewTab || in_array('noopener', $relTokens, true)) {
            $anchor .= ' rel="noopener"';
        }

        return $anchor . '>';
    }

    /**
     * @param string $tag
     * @return array<string, string>
     */
    private static function attributes($tag)
    {
        preg_match_all(
            '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/',
            $tag,
            $matches,
            PREG_SET_ORDER
        );

        $attributes = array();
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (!isset($attributes[$name])) {
                // PREG_SET_ORDER truncates each set at the last participating
                // group, so an empty quoted value leaves later groups absent.
                $attributes[$name] = $match[2] !== ''
                    ? $match[2]
                    : ((isset($match[3]) && $match[3] !== '') ? $match[3] : (isset($match[4]) ? $match[4] : ''));
            }
        }

        return $attributes;
    }
}
