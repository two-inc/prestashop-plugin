<?php

declare(strict_types=1);

/**
 * Coverage for getTwoVersionedAssetPath() (TWO-53PS).
 *
 * Assets were registered via registerJavascript()/registerStylesheet() with no
 * version/cache-busting parameter on the URL, so a stale copy could be served
 * from the browser cache or a CDN in front of it for hours after deploy. The
 * fix appends "?v=<filemtime>" to the module-relative path, which changes
 * whenever the file's content (and therefore mtime) changes.
 */
final class AssetCacheBustingSpec
{
    public static function runAll(): void
    {
        self::testPathIsPrefixedWithModuleDirectory();
        self::testVersionQueryStringMatchesFileMtime();
        self::testVersionChangesWhenFileMtimeChanges();
        self::testMissingFileFallsBackToUnversionedPath();
        self::testLeadingSlashOnRelativePathIsNormalised();
    }

    private static function callVersionedPath(string $relativePath)
    {
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoVersionedAssetPath');

        return $method->invoke($module, $relativePath);
    }

    private static function testPathIsPrefixedWithModuleDirectory(): void
    {
        $path = self::callVersionedPath('views/css/two.css');
        TinyAssert::true(
            strpos($path, 'modules/twopayment/views/css/two.css') === 0,
            "expected module-relative prefix, got: {$path}"
        );
    }

    private static function testVersionQueryStringMatchesFileMtime(): void
    {
        $module = new TwopaymentTestHarness();
        $realMtime = @filemtime(rtrim($module->local_path, '/') . '/views/css/two.css');
        TinyAssert::true($realMtime !== false, 'fixture file must exist for this assertion to be meaningful');

        $path = self::callVersionedPath('views/css/two.css');
        TinyAssert::same('modules/twopayment/views/css/two.css?v=' . $realMtime, $path);
    }

    private static function testVersionChangesWhenFileMtimeChanges(): void
    {
        $tmpFile = sys_get_temp_dir() . '/two-asset-cache-busting-spec-' . uniqid('', true) . '.js';
        file_put_contents($tmpFile, 'console.log("v1");');
        touch($tmpFile, 1000000000);

        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoVersionedAssetPath');

        // local_path is a dynamic property (mirrors PrestaShop's real
        // Module::$local_path, not declared on the stub) - point it at a
        // throwaway fixture rather than depending on real module asset
        // mtimes for this specific "changes on change" assertion.
        $module->local_path = dirname($tmpFile);

        $before = $method->invoke($module, basename($tmpFile));

        touch($tmpFile, 2000000000);
        clearstatcache(true, $tmpFile);

        $after = $method->invoke($module, basename($tmpFile));

        unlink($tmpFile);

        TinyAssert::true($before !== $after, 'versioned URL must change when the file mtime changes');
        TinyAssert::true(strpos($before, '?v=1000000000') !== false, "expected v1 mtime in: {$before}");
        TinyAssert::true(strpos($after, '?v=2000000000') !== false, "expected v2 mtime in: {$after}");
    }

    private static function testMissingFileFallsBackToUnversionedPath(): void
    {
        $path = self::callVersionedPath('views/js/modules/DoesNotExist-' . uniqid() . '.js');
        TinyAssert::true(strpos($path, '?v=') === false, "expected no version query string, got: {$path}");
        TinyAssert::true(strpos($path, 'modules/twopayment/views/js/modules/DoesNotExist') === 0, $path);
    }

    private static function testLeadingSlashOnRelativePathIsNormalised(): void
    {
        $withSlash = self::callVersionedPath('/views/css/two.css');
        $withoutSlash = self::callVersionedPath('views/css/two.css');
        TinyAssert::same($withoutSlash, $withSlash);
    }
}
