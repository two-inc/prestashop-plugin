<?php

declare(strict_types=1);

/**
 * Guards the module's translation catalogues against silent rot (TWO-24760).
 *
 * A translation catalogue fails in exactly one way: the key the runtime asks
 * for is not the key in the file, so the lookup misses and PrestaShop falls
 * back to the English source. There is no error, no warning and no log line —
 * the shop simply renders English, and a catalogue full of perfectly good
 * translations looks identical to one that works. `translations/es.php` has
 * been in that state for a long time without anyone noticing (see the
 * deliberate exclusion below), which is the whole reason this spec exists.
 *
 * HOW THE KEY IS BUILT. `Translate::getModuleTranslation()` looks up
 *
 *     <{twopayment}prestashop><source>_<md5>
 *
 * where <md5> hashes the source string with any run of backslashes before a
 * single quote collapsed to one escaped quote, and <source> depends on where
 * the string is written:
 *
 *   - PHP. Every `->l()` call in this module reaches `Module::l()` with no
 *     `$specific` argument, so `$source` is `$this->name` — the module name —
 *     for ALL of them. That includes the strings defined in the front
 *     controllers, which are called as `$this->module->l(...)`. Their source
 *     segment is `twopayment`, NOT the controller filename.
 *   - Templates. `smartyTranslate()` passes `basename($tpl, '.tpl')`, so
 *     template strings carry a per-template source segment.
 *
 * That asymmetry is the trap. The back office translation screen
 * (`AdminTranslationsController`) derives the segment from the *filename* in
 * both cases, so saving from it rewrites every PHP string to a key nothing
 * looks up. Do not regenerate these files from the back office; see the i18n
 * section of AGENTS.md.
 *
 * WHY es.php IS NOT GATED. It is known-drifted and predates this spec: only
 * part of it is reachable, some rows are keyed to controller filenames, and it
 * is missing strings that exist in the source today. Gating it would make the
 * build red on pre-existing content and would say nothing about the change
 * that turned it red. Repairing it is its own ticket; when that lands, add
 * 'es' to self::GATED_LOCALES and delete this paragraph.
 */
final class TranslationCatalogueSpec
{
    /**
     * Locales whose catalogues must be exactly in step with the source.
     * See the class comment before adding 'es'.
     */
    private const GATED_LOCALES = ['nl', 'no', 'sv'];

    private const PREFIX = '<{twopayment}prestashop>';

    /** Directories that hold no shippable translatable string. */
    private const SKIP_DIRS = ['tests', 'dev', 'node_modules', 'vendor', '.worktrees', '.git', 'translations'];

    public static function runAll(): void
    {
        $expected = self::extractExpectedKeys();

        // A catastrophic extraction failure (a moved file, a broken regex) would
        // otherwise make every assertion below vacuously pass.
        if (count($expected) < 300) {
            throw new RuntimeException(sprintf(
                'Extracted only %d translatable strings from the module source. That is far below the '
                . 'expected order of magnitude, so the extraction itself is broken — fix it rather than '
                . 'relaxing this floor.',
                count($expected)
            ));
        }

        // A tighter floor on the two segment shapes independently: the 300 floor
        // above has 146 strings of slack versus the real 446, wide enough that a
        // regex regression dropping a whole segment (e.g. every .tpl source)
        // could still clear it.
        $phpCount = 0;
        foreach (array_keys($expected) as $key) {
            if (strncmp($key, 'twopayment_', 11) === 0) {
                ++$phpCount;
            }
        }
        if ($phpCount < 330) {
            throw new RuntimeException(sprintf(
                'Extracted only %d twopayment_-segment (PHP ->l()) strings, expected north of 330. The PHP '
                . 'extraction regex likely regressed.',
                $phpCount
            ));
        }

        if (count(self::$tplSources) < 9) {
            throw new RuntimeException(sprintf(
                'Extracted distinct template source segments from only %d .tpl file(s), expected at least 9. '
                . 'The template extraction regex likely regressed.',
                count(self::$tplSources)
            ));
        }

        // Before the per-locale checks: an nb.php would otherwise surface only as
        // a generic "missing translations/no.php", which does not say why.
        self::assertNorwegianUsesIsoCodeFilename();

        foreach (self::GATED_LOCALES as $iso) {
            self::assertCatalogueMatches($iso, $expected);
        }
    }

    /** @var array<string, true> distinct .tpl source segments seen during extraction */
    private static array $tplSources = [];

    /**
     * @return array<string, string> runtime lookup key (without prefix) => English source
     */
    private static function extractExpectedKeys(): array
    {
        $root = dirname(__DIR__);
        $keys = [];
        self::$tplSources = [];

        // Both quote styles are legal PHP/Smarty syntax and both appear in this
        // module. Matching only ' silently drops any "->l("..."")" or
        // "{l s=\"...\"}" call site — the catalogue then looks complete while a
        // whole string quietly renders English forever.
        $singleQuoted = '\'(?:[^\'\\\\]|\\\\.)*\'';
        $doubleQuoted = '"(?:[^"\\\\]|\\\\.)*"';

        foreach (self::sourceFiles($root) as $path) {
            $contents = (string) file_get_contents($path);
            $isPhp = substr($path, -4) === '.php';

            if ($isPhp) {
                // Every ->l() here routes through Module::l() with no $specific,
                // so the source segment is the module name regardless of file.
                $pattern = '/->l\(\s*(' . $singleQuoted . '|' . $doubleQuoted . ')/';
                $source = 'twopayment';
                // Only count openers whose argument actually starts with a
                // quote — a handful of ->l() calls take a dynamic (non-literal)
                // argument (e.g. a merchant-configured brand label) and were
                // never extractable; those aren't a parity failure.
                $callSiteCount = preg_match_all('/->l\(\s*[\'"]/', $contents);
            } else {
                $pattern = '/\{l\s+s=\s*(' . $singleQuoted . '|' . $doubleQuoted . ')/';
                $source = strtolower(basename($path, '.tpl'));
                $callSiteCount = preg_match_all('/\{l\s+s=\s*[\'"]/', $contents);
            }

            $matchCount = preg_match_all($pattern, $contents, $matches);

            // Parity check: every call-site opener must have produced exactly
            // one captured literal. A mismatch means some call sites used a
            // quoting shape (or had an unbalanced/odd quote count) the capture
            // group didn't account for, and would otherwise be silently
            // skipped rather than failing loud.
            if ($matchCount !== $callSiteCount) {
                throw new RuntimeException(sprintf(
                    '%s has %d translatable call site(s) but only %d were extracted — a string literal '
                    . 'shape (quoting) is not being captured. Fix the extraction regex rather than the count.',
                    $path,
                    $callSiteCount,
                    $matchCount
                ));
            }

            if ($matchCount === false || $matchCount === 0) {
                continue;
            }

            if (!$isPhp) {
                self::$tplSources[$source] = true;
            }

            foreach ($matches[1] as $literal) {
                $isDouble = $literal !== '' && $literal[0] === '"';
                $raw = substr($literal, 1, -1);
                $string = $isDouble ? self::unescapeDoubleQuoted($raw) : self::unescapeSingleQuoted($raw);
                $keys[$source . '_' . self::hashSourceString($string)] = $string;
            }

            if ($isPhp) {
                self::assertNoSpecificArgument($path, $contents);
            }
        }

        return $keys;
    }

    /**
     * This module's whole key-derivation model depends on every ->l() call
     * omitting the optional $specific second argument (source segment is
     * always the module name). A call that passes one would still build a
     * plausible-looking key, but the runtime would look up a different
     * source segment than the one this spec assumes — following THIS spec's
     * own "missing key" fix advice in that case makes it worse, not better.
     * Enforce the invariant directly instead of relying on that symptom.
     */
    private static function assertNoSpecificArgument(string $path, string $contents): void
    {
        $pattern = '/->l\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,/';

        if (preg_match($pattern, $contents)) {
            throw new RuntimeException(sprintf(
                '%s has an ->l() call with a second ($specific) argument. This module\'s key derivation '
                . 'assumes every ->l() call resolves its source segment to the module name; a $specific '
                . 'argument breaks that assumption for that one call. Remove the argument.',
                $path
            ));
        }
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(string $root): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), self::SKIP_DIRS, true);
                    }

                    $name = $current->getFilename();

                    return substr($name, -4) === '.php' || substr($name, -4) === '.tpl';
                }
            )
        );

        foreach ($iterator as $file) {
            $found[] = $file->getPathname();
        }

        sort($found);

        return $found;
    }

    /**
     * Resolve a PHP single-quoted literal. Only \' and \\ are escapes there —
     * a blanket stripslashes() would wrongly turn a literal \n into n.
     */
    private static function unescapeSingleQuoted(string $raw): string
    {
        $out = '';
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] === '\\' && $i + 1 < $length && ($raw[$i + 1] === '\\' || $raw[$i + 1] === '\'')) {
                $out .= $raw[$i + 1];
                $i++;
                continue;
            }

            $out .= $raw[$i];
        }

        return $out;
    }

    /**
     * Resolve a PHP double-quoted literal. This module's source strings don't
     * rely on double-quote interpolation ($var, {$var}), so it is enough to
     * unescape the small set of backslash escapes PHP recognises there; a
     * literal \$ or \{ that slipped through unescaped would already have
     * failed php -l.
     */
    private static function unescapeDoubleQuoted(string $raw): string
    {
        $map = ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\t' => "\t", '\\r' => "\r", '\\$' => '$'];

        return strtr($raw, $map);
    }

    /**
     * @return list<string> ordered sprintf conversion tokens, e.g. ['%1$s', '%d']
     */
    private static function extractSprintfTokens(string $string): array
    {
        preg_match_all('/%(?:\d+\$)?[-+ 0\']*\d*(?:\.\d+)?[bcdeEufFgGosxX]|%%/', $string, $matches);

        return $matches[0];
    }

    /** Mirrors the hashing in Translate::getModuleTranslation(). */
    private static function hashSourceString(string $string): string
    {
        return md5((string) preg_replace("/\\\*'/", "\'", $string));
    }

    /**
     * @param array<string, string> $expected
     */
    private static function assertCatalogueMatches(string $iso, array $expected): void
    {
        $path = dirname(__DIR__) . '/translations/' . $iso . '.php';

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Missing translation catalogue translations/%s.php.', $iso));
        }

        $rows = self::loadCatalogue($path);

        $missing = array_diff_key($expected, $rows);
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'translations/%s.php is missing %d string(s) that the module looks up, so they will render '
                . 'in English. First few: %s',
                $iso,
                count($missing),
                self::sample(array_slice($missing, 0, 3, true))
            ));
        }

        $orphans = array_diff_key($rows, $expected);
        if ($orphans !== []) {
            throw new RuntimeException(sprintf(
                'translations/%s.php has %d row(s) keyed to something the module never looks up — either the '
                . 'source string changed, or the file was regenerated from the back office (which keys PHP '
                . 'strings by filename). First few keys: %s',
                $iso,
                count($orphans),
                implode(', ', array_slice(array_keys($orphans), 0, 3))
            ));
        }

        // The lookup guards with !empty(), so a row whose value is '' or '0'
        // reads as absent however well-formed the file is.
        foreach ($rows as $key => $value) {
            if ($value === '' || $value === '0') {
                throw new RuntimeException(sprintf(
                    'translations/%s.php row %s has a value that !empty() treats as missing, so the lookup '
                    . 'falls back to English.',
                    $iso,
                    $key
                ));
            }
        }

        // A translation that drops, adds or reorders an English source's
        // sprintf conversions (e.g. %s -> %d, or a %1$s/%2$s swap) renders a
        // sprintf() warning or the wrong value at runtime, and neither the row
        // count nor a plain string diff catches it.
        foreach ($rows as $key => $value) {
            if (!array_key_exists($key, $expected)) {
                continue; // already reported as an orphan above
            }

            $expectedTokens = self::extractSprintfTokens($expected[$key]);
            $actualTokens = self::extractSprintfTokens($value);

            if ($expectedTokens !== $actualTokens) {
                throw new RuntimeException(sprintf(
                    'translations/%s.php row %s has sprintf tokens %s but the English source has %s.',
                    $iso,
                    $key,
                    $actualTokens === [] ? '(none)' : implode(' ', $actualTokens),
                    $expectedTokens === [] ? '(none)' : implode(' ', $expectedTokens)
                ));
            }
        }
    }

    /**
     * @return array<string, string> key without the module/theme prefix => translation
     */
    private static function loadCatalogue(string $path): array
    {
        $_MODULE = [];
        require $path;

        $rows = [];
        foreach ($_MODULE as $key => $value) {
            if (strpos($key, self::PREFIX) !== 0) {
                throw new RuntimeException(sprintf(
                    'Row %s in %s does not carry the %s prefix. Translate::getModuleTranslation() checks a '
                    . 'theme-scoped key ("<{twopayment}<theme>>...") before this one, so a row like this is '
                    . 'reachable in general — just never for us, because this module ships exactly one '
                    . 'catalogue shared by every theme, not a per-theme one. Prefix the row with %s.',
                    $key,
                    basename($path),
                    self::PREFIX
                ));
            }

            $raw = (string) $value;

            // getModuleTranslation() applies stripslashes() on the way out, which
            // silently eats ANY backslash, not just an intentional \' or \\
            // escape. A stray literal backslash in a value would pass every
            // check above (it is not '' or '0', it round-trips through the PHP
            // parser fine) yet still render wrong, because stripslashes() would
            // consume it and the character(s) after it. Catch it before that
            // silent step, not after.
            if (preg_match('/\\\\(?!\\\\|\')/', $raw)) {
                throw new RuntimeException(sprintf(
                    'Row %s in %s has a literal backslash outside a \\\' or \\\\ escape. '
                    . 'stripslashes() will silently consume it (and the character after it) at lookup time.',
                    $key,
                    basename($path)
                ));
            }

            // stripslashes mirrors what getModuleTranslation applies on the way out.
            $rows[substr($key, strlen(self::PREFIX))] = stripslashes($raw);
        }

        return $rows;
    }

    /**
     * Norwegian is `no`, PrestaShop's iso_code, and never the `nb` locale tag
     * the sibling plugins use. `getModuleTranslation()` builds the path from
     * iso_code, so an nb.php is read by nothing at all — no error, no log line.
     */
    private static function assertNorwegianUsesIsoCodeFilename(): void
    {
        $dir = dirname(__DIR__) . '/translations';

        if (is_file($dir . '/nb.php')) {
            throw new RuntimeException(
                'translations/nb.php will never be loaded: PrestaShop resolves a module catalogue by '
                . 'iso_code, and its iso_code for Norwegian is "no". Rename it to translations/no.php.'
            );
        }

        if (!is_file($dir . '/no.php')) {
            throw new RuntimeException('Missing translations/no.php (Norwegian, by PrestaShop iso_code).');
        }
    }

    /**
     * @param array<string, string> $rows
     */
    private static function sample(array $rows): string
    {
        $parts = [];
        foreach ($rows as $key => $english) {
            $parts[] = sprintf('%s (%s)', $key, substr($english, 0, 60));
        }

        return implode('; ', $parts);
    }
}
