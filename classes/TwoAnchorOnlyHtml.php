<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * Reduces buyer-facing copy to text plus links: an `<a>` with an http(s) href
 * survives, every other tag is dropped and its text kept, and all other markup
 * is escaped.
 *
 * Surviving anchors are rebuilt from scratch rather than filtered, so no
 * attribute this module does not itself emit can reach the page.
 */
class TwoAnchorOnlyHtml
{
    /**
     * @param mixed $html
     * @return string
     */
    public static function escape($html)
    {
        $parts = preg_split('/(<[^>]*>)/', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return '';
        }

        $result = '';
        $openAnchors = 0;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $result .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8', false);
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
     * @param string $tag
     * @return string the anchor rebuilt from its allowed attributes, or ''
     *                when the href is not an http(s) URL
     */
    private static function rebuildAnchor($tag)
    {
        $attributes = self::attributes($tag);
        $href = html_entity_decode(trim(isset($attributes['href']) ? $attributes['href'] : ''), ENT_QUOTES, 'UTF-8');
        if (!preg_match('/^https?:\/\//i', $href)) {
            return '';
        }

        $anchor = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
        if (isset($attributes['target']) && strtolower(trim($attributes['target'])) === '_blank') {
            $anchor .= ' target="_blank"';
        }
        if (isset($attributes['rel']) && strtolower(trim($attributes['rel'])) === 'noopener') {
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
                $attributes[$name] = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : (isset($match[4]) ? $match[4] : ''));
            }
        }

        return $attributes;
    }
}
