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
     * The regression test, done as a source-slice (this hook depends on
     * Country::getCountries() and live merchant-term/FX refreshes that the
     * test stubs don't model, so it can't safely be invoked end-to-end here -
     * same constraint CompanySearchCountrySourcingSpec works around for the
     * same hook).
     *
     * Counts call sites rather than searching for the literal "?v=" text,
     * because this very file's comments now say "?v=<mtime>" while explaining
     * the bug the fix guards against - a text search would be fooled by its
     * own documentation. Instead: every register{Javascript,Stylesheet}() call
     * in the hook body must build its path via getTwoModuleAssetPath() (never
     * the removed getTwoVersionedAssetPath()) and must pass a
     * 'version' => $this->getTwoAssetVersion(...) option. Reverting any one
     * call site back to appending the version onto the path - the change that
     * broke checkout in PR #127/TWO-53PS - drops that call's contribution to
     * one or both counts, and this fails.
     */
    private static function testCheckoutHookUsesCleanPathAndVersionParamForEveryRegisteredAsset(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::true($source !== false, 'could not read twopayment.php');

        $start = strpos($source, 'public function hookActionFrontControllerSetMedia()');
        TinyAssert::true($start !== false, 'hookActionFrontControllerSetMedia() not found in twopayment.php');
        $end = strpos($source, 'private function getTwoModuleAssetPath', $start);
        TinyAssert::true($end !== false, 'getTwoModuleAssetPath() not found after the hook - has it moved or been removed?');

        $body = substr($source, $start, $end - $start);

        TinyAssert::false(
            strpos($body, 'getTwoVersionedAssetPath') !== false,
            'hookActionFrontControllerSetMedia() still references the removed getTwoVersionedAssetPath() - '
            . 'that helper appended the cache-busting version onto the path itself, which core silently '
            . 'fails to resolve via file_exists() and drops the asset entirely (TWO-53PS regression).'
        );

        $registerCallCount = substr_count($body, '->registerJavascript(') + substr_count($body, '->registerStylesheet(');
        $cleanPathCount = substr_count($body, 'getTwoModuleAssetPath(');
        $versionParamCount = substr_count($body, "'version' => \$this->getTwoAssetVersion(");

        TinyAssert::true($registerCallCount >= 7, "expected at least 7 register*() calls in the hook, found {$registerCallCount}");
        TinyAssert::same($registerCallCount, $cleanPathCount, 'every register*() call must build its path via getTwoModuleAssetPath()');
        TinyAssert::same($registerCallCount, $versionParamCount, "every register*() call must pass 'version' => \$this->getTwoAssetVersion(...)");
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
