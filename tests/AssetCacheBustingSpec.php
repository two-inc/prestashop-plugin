<?php

declare(strict_types=1);

/**
 * Coverage for getTwoModuleAssetPath()/getTwoAssetVersion() (TWO-53PS, and the
 * regression it caused - fixed here).
 *
 * PrestaShop's register{Stylesheet,Javascript}() resolve the relative path
 * string to a real file via a literal file_exists() check
 * (classes/assets/AbstractAssetManager.php getFullPath()) BEFORE the asset is
 * added to the render list; the 'version' param is only appended to the URL
 * AFTER that resolution succeeds (classes/assets/JavascriptManager.php /
 * StylesheetManager.php add()). TWO-53PS originally cache-busted by appending
 * "?v=<mtime>" directly onto the path passed into register*() - which meant
 * file_exists() was checking a path that could never be a real file, so core
 * silently dropped every Two JS/CSS asset with no error, exception, or log
 * line. Config injected via Media::addJsDef() kept working (it doesn't go
 * through this path), which is why the bug looked like a partial page load
 * rather than a hard failure. The fix: pass the clean relative path, and put
 * the cache-busting value in the 'version' param core already supports.
 *
 * The regression test below models core's real file_exists()-before-version
 * behaviour in the stub controller, so it fails the same way production did
 * if the path handed to register*() ever again carries a query string.
 */
final class AssetCacheBustingSpec
{
    public static function runAll(): void
    {
        self::testModuleAssetPathCarriesNoQueryString();
        self::testAssetVersionMatchesFileMtime();
        self::testAssetVersionChangesWhenFileMtimeChanges();
        self::testMissingFileYieldsNullVersion();
        self::testLeadingSlashOnRelativePathIsNormalised();
        self::testCheckoutHookUsesCleanPathAndVersionParamForEveryRegisteredAsset();
        self::testAddCssFallbackCarriesNoQueryString();
    }

    private static function callModuleAssetPath(string $relativePath)
    {
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoModuleAssetPath');

        return $method->invoke($module, $relativePath);
    }

    private static function callAssetVersion(string $relativePath, ?string $localPath = null)
    {
        $module = new TwopaymentTestHarness();
        if ($localPath !== null) {
            $module->local_path = $localPath;
        }
        $method = new ReflectionMethod(Twopayment::class, 'getTwoAssetVersion');

        return $method->invoke($module, $relativePath);
    }

    private static function testModuleAssetPathCarriesNoQueryString(): void
    {
        $path = self::callModuleAssetPath('views/css/two.css');
        TinyAssert::same('modules/twopayment/views/css/two.css', $path);
        TinyAssert::false(strpos($path, '?') !== false, "module asset path must never carry a query string, got: {$path}");
    }

    private static function testAssetVersionMatchesFileMtime(): void
    {
        $module = new TwopaymentTestHarness();
        $realMtime = @filemtime(rtrim($module->local_path, '/') . '/views/css/two.css');
        TinyAssert::true($realMtime !== false, 'fixture file must exist for this assertion to be meaningful');

        $version = self::callAssetVersion('views/css/two.css');
        TinyAssert::same((string) $realMtime, $version);
    }

    private static function testAssetVersionChangesWhenFileMtimeChanges(): void
    {
        $tmpFile = sys_get_temp_dir() . '/two-asset-cache-busting-spec-' . uniqid('', true) . '.js';
        file_put_contents($tmpFile, 'console.log("v1");');
        touch($tmpFile, 1000000000);

        $before = self::callAssetVersion(basename($tmpFile), dirname($tmpFile));

        touch($tmpFile, 2000000000);
        clearstatcache(true, $tmpFile);

        $after = self::callAssetVersion(basename($tmpFile), dirname($tmpFile));

        unlink($tmpFile);

        TinyAssert::true($before !== $after, 'version must change when the file mtime changes');
        TinyAssert::same('1000000000', $before);
        TinyAssert::same('2000000000', $after);
    }

    private static function testMissingFileYieldsNullVersion(): void
    {
        $version = self::callAssetVersion('views/js/modules/DoesNotExist-' . uniqid() . '.js');
        TinyAssert::true($version === null, 'missing file must yield a null version, not a query string');
    }

    private static function testLeadingSlashOnRelativePathIsNormalised(): void
    {
        $withSlash = self::callModuleAssetPath('/views/css/two.css');
        $withoutSlash = self::callModuleAssetPath('views/css/two.css');
        TinyAssert::same($withoutSlash, $withSlash);
    }

    /**
     * Strips PHP comments before any token-matching below. Not a general PHP
     * tokenizer - just enough to stop a decoy comment (block or trailing
     * line-comment) from padding out the tokens a regression assertion greps
     * for. Safe for the narrow slices this spec scans: none of the string
     * literals in the register*() calls it guards contain "//" or "/*".
     *
     * Round-2 adversarial review (Vader) found the previous per-line version
     * of this test could be defeated by a decoy TRAILING COMMENT on the same
     * line as a reverted call - e.g. `...->registerJavascript('id', 'path' .
     * '?v=' . @filemtime(...), [...]); // getTwoModuleAssetPath( 'version' =>
     * $this->getTwoAssetVersion(` - which contains both guarded-for tokens
     * without ever really calling either. Stripping comments first closes
     * that gap.
     */
    private static function stripComments(string $php): string
    {
        $noBlockComments = preg_replace('#/\*.*?\*/#s', '', $php);
        return preg_replace('#//[^\n]*#', '', $noBlockComments);
    }

    /**
     * Extracts each `->$methodCall(...)` statement as its own string, from
     * the method name through the matching (paren-depth-balanced) closing
     * `)`. Round-2 adversarial review (Han/Vader) found the previous per-LINE
     * version of this test both missed the admin hook's registerStylesheet()
     * call (which spans several lines) and was fragile against any future
     * legitimate line-wrap of a checkout-hook call. Matching the whole
     * statement, wherever its parens close, fixes both: multi-line calls are
     * captured whole, and a decoy elsewhere in the body can no longer stand
     * in for tokens the call itself must carry.
     *
     * @return array<int, string> one entry per call site found
     */
    private static function extractCallStatements(string $source, string $methodCall): array
    {
        $statements = array();
        $offset = 0;
        $length = strlen($source);

        while (($pos = strpos($source, $methodCall, $offset)) !== false) {
            $parenStart = strpos($source, '(', $pos);
            TinyAssert::true($parenStart !== false, "malformed call site for {$methodCall} - no opening paren found");

            $depth = 0;
            $i = $parenStart;
            for (; $i < $length; $i++) {
                if ($source[$i] === '(') {
                    ++$depth;
                } elseif ($source[$i] === ')') {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            TinyAssert::true($depth === 0, "unbalanced parens for {$methodCall} call starting at offset {$pos}");

            $statements[] = substr($source, $pos, $i - $pos + 1);
            $offset = $i + 1;
        }

        return $statements;
    }

    /**
     * The regression test, done as a source-slice (this hook depends on
     * Country::getCountries() and live merchant-term/FX refreshes that the
     * test stubs don't model, so it can't safely be invoked end-to-end here -
     * same constraint CompanySearchCountrySourcingSpec works around for the
     * same hook).
     *
     * Every register{Javascript,Stylesheet}() call site - in BOTH
     * hookActionFrontControllerSetMedia() (checkout) and
     * hookActionAdminControllerSetMedia() (admin/order pages, TWO-53PS's
     * registerStylesheet() call there uses the identical pattern) - must
     * build its path via getTwoModuleAssetPath() (never the removed
     * getTwoVersionedAssetPath()) and must pass a
     * 'version' => $this->getTwoAssetVersion(...) option, checked against
     * comment-stripped source and the whole paren-balanced call statement
     * rather than a raw line - see stripComments() and
     * extractCallStatements() for what each closes off. Reverting any one
     * call site back to appending the version onto the path - the change
     * that broke checkout in PR #127/TWO-53PS - drops that call's own
     * statement text below both tokens, and this fails.
     */
    private static function testCheckoutHookUsesCleanPathAndVersionParamForEveryRegisteredAsset(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::true($source !== false, 'could not read twopayment.php');

        $frontStart = strpos($source, 'public function hookActionFrontControllerSetMedia()');
        TinyAssert::true($frontStart !== false, 'hookActionFrontControllerSetMedia() not found in twopayment.php');
        $frontEnd = strpos($source, 'private function getTwoModuleAssetPath', $frontStart);
        TinyAssert::true($frontEnd !== false, 'getTwoModuleAssetPath() not found after the hook - has it moved or been removed?');

        $adminStart = strpos($source, 'public function hookActionAdminControllerSetMedia()');
        TinyAssert::true($adminStart !== false, 'hookActionAdminControllerSetMedia() not found in twopayment.php');
        $adminEnd = strpos($source, 'public function hookPaymentOptions', $adminStart);
        TinyAssert::true($adminEnd !== false, 'hookPaymentOptions() not found after the admin hook - has it moved?');

        $frontBody = self::stripComments(substr($source, $frontStart, $frontEnd - $frontStart));
        $adminBody = self::stripComments(substr($source, $adminStart, $adminEnd - $adminStart));

        foreach (array('hookActionFrontControllerSetMedia' => $frontBody, 'hookActionAdminControllerSetMedia' => $adminBody) as $hookName => $body) {
            TinyAssert::false(
                strpos($body, 'getTwoVersionedAssetPath') !== false,
                "{$hookName}() still references the removed getTwoVersionedAssetPath() - that helper appended "
                . 'the cache-busting version onto the path itself, which core silently fails to resolve via '
                . 'file_exists() and drops the asset entirely (TWO-53PS regression).'
            );
        }

        $callStatements = array_merge(
            self::extractCallStatements($frontBody, '->registerJavascript('),
            self::extractCallStatements($frontBody, '->registerStylesheet('),
            self::extractCallStatements($adminBody, '->registerStylesheet(')
        );

        TinyAssert::true(count($callStatements) >= 8, 'expected at least 8 register*() call sites (7+ checkout, 1+ admin), found ' . count($callStatements));

        foreach ($callStatements as $statement) {
            TinyAssert::true(
                strpos($statement, 'getTwoModuleAssetPath(') !== false,
                "register*() call must build its path via getTwoModuleAssetPath(), got: {$statement}"
            );
            TinyAssert::true(
                strpos($statement, "'version' => \$this->getTwoAssetVersion(") !== false,
                "register*() call must pass 'version' => \$this->getTwoAssetVersion(...), got: {$statement}"
            );
        }
    }

    /**
     * Pre-1.7 admin controllers without registerStylesheet() fall back to the
     * legacy addCSS($url) API. That branch builds its own URL from
     * $this->_path and must NOT append a version query string onto it - the
     * same file_exists()-on-a-query-string trap applies there too, via
     * Controller::addCSS() -> getAssetUriFromLegacyDeprecatedMethod() ->
     * registerStylesheet($id, $uri).
     */
    private static function testAddCssFallbackCarriesNoQueryString(): void
    {
        $module = new TwopaymentTestHarness();

        $controller = new class {
            public $controller_name = 'AdminModules';
            public $php_self = 'module';
            public $addedCss = [];

            public function addCSS($url)
            {
                $this->addedCss[] = $url;
            }
        };

        $module->context->controller = $controller;
        Tools::setTestValue('configure', 'twopayment');
        Tools::setTestValue('controller', 'AdminModules');

        $module->hookActionAdminControllerSetMedia();

        Tools::resetTestValues();

        TinyAssert::same(1, count($controller->addedCss));
        TinyAssert::false(strpos($controller->addedCss[0], '?') !== false, "addCSS fallback must carry no query string, got: {$controller->addedCss[0]}");
    }
}
