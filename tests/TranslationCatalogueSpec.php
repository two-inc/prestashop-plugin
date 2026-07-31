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

        // Before the per-locale checks: an nb.php would otherwise surface only as
        // a generic "missing translations/no.php", which does not say why.
        self::assertNorwegianUsesIsoCodeFilename();

        foreach (self::GATED_LOCALES as $iso) {
            self::assertCatalogueMatches($iso, $expected);
        }
    }

    /**
     * @return array<string, string> runtime lookup key (without prefix) => English source
     */
    private static function extractExpectedKeys(): array
    {
        $root = dirname(__DIR__);
        $keys = [];

        foreach (self::sourceFiles($root) as $path) {
            $contents = (string) file_get_contents($path);
            $isPhp = substr($path, -4) === '.php';

            if ($isPhp) {
                // Every ->l() here routes through Module::l() with no $specific,
                // so the source segment is the module name regardless of file.
                $pattern = '/->l\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/';
                $source = 'twopayment';
            } else {
                $pattern = '/\{l\s+s=\s*\'((?:[^\'\\\\]|\\\\.)*)\'/';
                $source = strtolower(basename($path, '.tpl'));
            }

            if (!preg_match_all($pattern, $contents, $matches)) {
                continue;
            }

            foreach ($matches[1] as $raw) {
                $string = self::unescapeSingleQuoted($raw);
                $keys[$source . '_' . self::hashSourceString($string)] = $string;
            }
        }

        return $keys;
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
                    'Row %s in %s does not carry the %s prefix, so no lookup can reach it.',
                    $key,
                    basename($path),
                    self::PREFIX
                ));
            }

            // stripslashes mirrors what getModuleTranslation applies on the way out.
            $rows[substr($key, strlen(self::PREFIX))] = stripslashes((string) $value);
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
