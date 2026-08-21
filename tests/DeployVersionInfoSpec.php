<?php

declare(strict_types=1);

final class DeployVersionInfoSpec
{
    public static function runAll(): void
    {
        self::testMissingGitDirReturnsNull();
        self::testRefHeadWithLooseRefReturnsShortSha();
        self::testRefHeadFallsBackToPackedRefs();
        self::testPackedRefsExactMatchIgnoresSuffixDecoy();
        self::testDetachedHeadReturnsShortSha();
        self::testGarbageHeadReturnsNull();
        self::testMissingRefAndPackedRefsReturnsNull();
        self::testGitDirTakesPrecedenceOverSidecar();
        self::testInvalidSidecarFallsBackToGitDir();
        self::testGitlinkFileReturnsShortSha();
        self::testGitlinkTakesPrecedenceOverValidSidecar();
        self::testEmptySidecarFallsBackToGitlinkFile();
        self::testGarbageSidecarFallsBackToGitlinkFile();
        self::testMalformedGitlinkFallsBackToValidSidecar();
        self::testUnreadableGitlinkFallsBackToValidSidecar();
        self::testNeitherSidecarNorGitReturnsNull();
        self::testGitlinkFileWithoutShaReturnsNull();
        self::testDeployedAtLabelMatchesModuleFileMtime();
        self::testClientVersionHasNoTrailingPlusWithoutSha();
        self::testClientVersionAppendsShaWhenResolved();
        self::testClientParamsAreUrlEncodedWithPercent2B();
        self::testBrowserConfigPublishesTheShaSuffixedVersion();
    }

    /**
     * The browser identifies itself with the same `client`/`client_v` pair the
     * PHP calls send, read out of the config this hook publishes - a different
     * version there makes one shop report itself as two.
     */
    private static function testBrowserConfigPublishesTheShaSuffixedVersion(): void
    {
        $module = new TwopaymentClientVersionWithShaHarness();
        $module->_path = '/modules/twopayment/';
        $controller = new class extends ModuleFrontController {
            public $php_self = 'order';
            public $controller_name = 'order';

            public function registerStylesheet($id, $path, $options = [])
            {
            }

            public function registerJavascript($id, $path, $options = [])
            {
            }

            public function addJquery()
            {
            }

            public function addJqueryUI($component)
            {
            }
        };
        $controller->module = $module;
        $module->context->controller = $controller;
        $module->context->country = new class {
            public $iso_code = 'NO';
        };
        // run.php runs every spec in ONE process, so both of these are shared
        // mutable state: restore whatever was there rather than leaving this
        // spec's country row and JS payload behind for whichever spec runs next.
        $countryWasSet = array_key_exists(578, StubStore::$countries);
        $previousCountry = $countryWasSet ? StubStore::$countries[578] : null;
        StubStore::$countries[578] = 'NO';

        Media::reset();
        try {
            $module->hookActionFrontControllerSetMedia();

            $published = Media::$jsDef['twopayment'];
        } finally {
            Media::reset();
            if ($countryWasSet) {
                StubStore::$countries[578] = $previousCountry;
            } else {
                unset(StubStore::$countries[578]);
            }
        }

        TinyAssert::same(
            $module->getTwoClientVersion(),
            $published['client_version'],
            'the config handed to the browser must carry the SAME version the PHP calls send'
        );
        TinyAssert::same('2.4.0+fbdc80b', $published['client_version']);
        TinyAssert::false(
            $published['client_version'] === '2.4.0',
            'the published version dropped its +<sha7> suffix - it is sourced from $this->version again'
        );
        TinyAssert::same(
            $module->getTwoClientParams()['client'],
            $published['client'],
            'the client id handed to the browser must be the one getTwoClientParams() sends'
        );
        TinyAssert::same('PS', $published['client']);
    }

    private static function callCommitHash(?string $gitDir, ?string $sidecarFile = null): ?string
    {
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoDeployedCommitHash');

        return $method->invoke($module, $gitDir, $sidecarFile);
    }

    private static function makeTempGitDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'two-deploy-spec-' . uniqid('', true) . DIRECTORY_SEPARATOR . '.git';
        mkdir($dir, 0777, true);

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private static function testMissingGitDirReturnsNull(): void
    {
        TinyAssert::same(null, self::callCommitHash(sys_get_temp_dir() . '/two-deploy-spec-nonexistent-' . uniqid()));
    }

    private static function testRefHeadWithLooseRefReturnsShortSha(): void
    {
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "ref: refs/heads/staging\n");
        mkdir($git . '/refs/heads', 0777, true);
        file_put_contents($git . '/refs/heads/staging', "abcdef0123456789abcdef0123456789abcdef01\n");

        TinyAssert::same('abcdef0', self::callCommitHash($git));
        self::removeDir(dirname($git));
    }

    private static function testRefHeadFallsBackToPackedRefs(): void
    {
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "ref: refs/heads/staging\n");
        file_put_contents(
            $git . '/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted\n"
            . "1111111111111111111111111111111111111111 refs/heads/other\n"
            . "fedcba9876543210fedcba9876543210fedcba98 refs/heads/staging\n"
        );

        TinyAssert::same('fedcba9', self::callCommitHash($git));
        self::removeDir(dirname($git));
    }

    private static function testPackedRefsExactMatchIgnoresSuffixDecoy(): void
    {
        // The decoy ref merely *ends with* the target ref path and is listed
        // first, so a naive suffix comparison would return its sha.
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "ref: refs/heads/staging\n");
        file_put_contents(
            $git . '/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted\n"
            . "decadedecadedecadedecadedecadedecadedeca refs/namespaces/foo/refs/heads/staging\n"
            . "fedcba9876543210fedcba9876543210fedcba98 refs/heads/staging\n"
        );

        TinyAssert::same('fedcba9', self::callCommitHash($git));
        self::removeDir(dirname($git));
    }

    private static function testDetachedHeadReturnsShortSha(): void
    {
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "0123456789abcdef0123456789abcdef01234567\n");

        TinyAssert::same('0123456', self::callCommitHash($git));
        self::removeDir(dirname($git));
    }

    private static function testGarbageHeadReturnsNull(): void
    {
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "not-a-sha-or-ref\n");

        TinyAssert::same(null, self::callCommitHash($git));
        self::removeDir(dirname($git));
    }

    private static function testMissingRefAndPackedRefsReturnsNull(): void
    {
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "ref: refs/heads/staging\n");

        TinyAssert::same(null, self::callCommitHash($git));
        self::removeDir(dirname($git));
    }

    private static function testGitDirTakesPrecedenceOverSidecar(): void
    {
        // TWO-25194: live git state wins over the build-time sidecar stamp,
        // which is frozen when package-release.sh runs.
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "ref: refs/heads/staging\n");
        mkdir($git . '/refs/heads', 0777, true);
        file_put_contents($git . '/refs/heads/staging', "abcdef0123456789abcdef0123456789abcdef01\n");
        $sidecar = dirname($git) . '/.two-deployed-commit';
        file_put_contents($sidecar, "1234abc\n");

        TinyAssert::same('abcdef0', self::callCommitHash($git, $sidecar));
        self::removeDir(dirname($git));
    }

    private static function testInvalidSidecarFallsBackToGitDir(): void
    {
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "ref: refs/heads/staging\n");
        mkdir($git . '/refs/heads', 0777, true);
        file_put_contents($git . '/refs/heads/staging', "abcdef0123456789abcdef0123456789abcdef01\n");
        $sidecar = dirname($git) . '/.two-deployed-commit';
        file_put_contents($sidecar, "not a sha\n");

        TinyAssert::same('abcdef0', self::callCommitHash($git, $sidecar));
        self::removeDir(dirname($git));
    }

    private static function makeTempModuleDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'two-deploy-spec-' . uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }

    private static function testGitlinkFileReturnsShortSha(): void
    {
        // Git-synced staging shops materialise the module as a LINKED WORKTREE:
        // `.git` is a file containing `gitdir: ../../.git/worktrees/<sha>`.
        $dir = self::makeTempModuleDir();
        $gitlink = $dir . '/.git';
        file_put_contents($gitlink, "gitdir: ../../.git/worktrees/fbdc80b92070eded9a2acbef222da0d55ac4af48\n");

        TinyAssert::same('fbdc80b', self::callCommitHash($gitlink));
        self::removeDir($dir);
    }

    private static function testGitlinkTakesPrecedenceOverValidSidecar(): void
    {
        // TWO-25194: the gitlink reflects what git-sync has checked out right
        // now, and package-release.sh removes its stamp only via an EXIT trap,
        // so an interrupted run leaves a valid-looking stale sidecar behind.
        $dir = self::makeTempModuleDir();
        $gitlink = $dir . '/.git';
        file_put_contents($gitlink, "gitdir: ../../.git/worktrees/fbdc80b92070eded9a2acbef222da0d55ac4af48\n");
        $sidecar = $dir . '/.two-deployed-commit';
        file_put_contents($sidecar, "1234abc\n");

        TinyAssert::same('fbdc80b', self::callCommitHash($gitlink, $sidecar));
        self::removeDir($dir);
    }

    private static function testMalformedGitlinkFallsBackToValidSidecar(): void
    {
        // TWO-25194: a readable gitlink carrying no worktree sha (plain gitdir
        // pointer) must fall through to the sidecar, not return null.
        $dir = self::makeTempModuleDir();
        $gitlink = $dir . '/.git';
        file_put_contents($gitlink, "gitdir: /srv/repos/prestashop-plugin/.git\n");
        $sidecar = $dir . '/.two-deployed-commit';
        file_put_contents($sidecar, "1234abc\n");

        TinyAssert::same('1234abc', self::callCommitHash($gitlink, $sidecar));
        self::removeDir($dir);
    }

    private static function testUnreadableGitlinkFallsBackToValidSidecar(): void
    {
        $dir = self::makeTempModuleDir();
        $gitlink = $dir . '/.git';
        file_put_contents($gitlink, "gitdir: ../../.git/worktrees/fbdc80b92070eded9a2acbef222da0d55ac4af48\n");
        $sidecar = $dir . '/.two-deployed-commit';
        file_put_contents($sidecar, "1234abc\n");
        @chmod($gitlink, 0000);

        // A user that bypasses mode bits (root in CI containers) keeps the file
        // readable no matter the chmod.
        if (!is_readable($gitlink)) {
            TinyAssert::same('1234abc', self::callCommitHash($gitlink, $sidecar));
        }

        @chmod($gitlink, 0644);
        self::removeDir($dir);
    }

    private static function testEmptySidecarFallsBackToGitlinkFile(): void
    {
        // Broken deploy infra writes the staging sidecar as a 0-byte file.
        $dir = self::makeTempModuleDir();
        $gitlink = $dir . '/.git';
        file_put_contents($gitlink, "gitdir: ../../.git/worktrees/fbdc80b92070eded9a2acbef222da0d55ac4af48\n");
        $sidecar = $dir . '/.two-deployed-commit';
        file_put_contents($sidecar, '');

        TinyAssert::same('fbdc80b', self::callCommitHash($gitlink, $sidecar));
        self::removeDir($dir);
    }

    private static function testGarbageSidecarFallsBackToGitlinkFile(): void
    {
        $dir = self::makeTempModuleDir();
        $gitlink = $dir . '/.git';
        file_put_contents($gitlink, "gitdir: ../../.git/worktrees/fbdc80b92070eded9a2acbef222da0d55ac4af48\n");
        $sidecar = $dir . '/.two-deployed-commit';
        file_put_contents($sidecar, "<html>404 not found</html>\n");

        TinyAssert::same('fbdc80b', self::callCommitHash($gitlink, $sidecar));
        self::removeDir($dir);
    }

    private static function testNeitherSidecarNorGitReturnsNull(): void
    {
        $dir = self::makeTempModuleDir();

        TinyAssert::same(null, self::callCommitHash($dir . '/.git', $dir . '/.two-deployed-commit'));
        self::removeDir($dir);
    }

    private static function testGitlinkFileWithoutShaReturnsNull(): void
    {
        $dir = self::makeTempModuleDir();
        $gitlink = $dir . '/.git';
        file_put_contents($gitlink, "gitdir: /srv/repos/prestashop-plugin/.git\n");

        TinyAssert::same(null, self::callCommitHash($gitlink));
        self::removeDir($dir);
    }

    private static function testClientVersionHasNoTrailingPlusWithoutSha(): void
    {
        $module = new TwopaymentClientVersionNoShaHarness();
        TinyAssert::same('2.4.0', $module->getTwoClientVersion());
        TinyAssert::same(
            array('client' => 'PS', 'client_v' => '2.4.0'),
            $module->getTwoClientParams()
        );
    }

    private static function testClientVersionAppendsShaWhenResolved(): void
    {
        $module = new TwopaymentClientVersionWithShaHarness();
        TinyAssert::same('2.4.0+fbdc80b', $module->getTwoClientVersion());
    }

    private static function testClientParamsAreUrlEncodedWithPercent2B(): void
    {
        // `+` is a literal SPACE in an application/x-www-form-urlencoded query value,
        // so the build MUST percent-encode it as %2B or the API sees "2.4.0 fbdc80b".
        $module = new TwopaymentClientVersionWithShaHarness();
        $query = http_build_query($module->getTwoClientParams());

        TinyAssert::same('client=PS&client_v=2.4.0%2Bfbdc80b', $query);

        $parsed = [];
        parse_str($query, $parsed);
        TinyAssert::same('2.4.0+fbdc80b', $parsed['client_v']);
    }

    private static function testDeployedAtLabelMatchesModuleFileMtime(): void
    {
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoDeployedAtLabel');
        $label = $method->invoke($module);

        $mtime = filemtime(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::same(date('Y-m-d H:i:s', $mtime), $label);
    }
}

final class TwopaymentClientVersionNoShaHarness extends TwopaymentTestHarness
{
    protected function getTwoDeployedCommitHash($git_dir = null, $sidecar_file = null)
    {
        return null;
    }
}

final class TwopaymentClientVersionWithShaHarness extends TwopaymentTestHarness
{
    protected function getTwoDeployedCommitHash($git_dir = null, $sidecar_file = null)
    {
        return 'fbdc80b';
    }
}
