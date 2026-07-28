<?php

declare(strict_types=1);

/**
 * Coverage for the deploy-version helpers shown on the Plugin Information tab:
 * getTwoDeployedCommitHash() (file-read-only .git inspection, fail-soft) and
 * getTwoDeployedAtLabel() (module file mtime, fail-soft).
 */
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
        // A packed-refs line whose full ref merely *ends with* the same string as our
        // target ref (e.g. a nested/differently-prefixed namespace) must NOT be treated
        // as a match — only an exact ref-path match is acceptable. The decoy is listed
        // first (and would win under a naive suffix comparison since it breaks on first
        // hit), the real ref is listed second with a different sha.
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
        // TWO-25194: live git state wins over the build-time sidecar stamp. The stamp is
        // frozen when package-release.sh runs, so a stamped tree that is later checked
        // out over would otherwise keep reporting the stale build sha forever.
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
        // A sidecar file with non-sha contents (or empty) must not short-circuit the
        // .git-directory fallback.
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
        // TWO-25194: the gitlink reflects what the git-sync loop has checked out RIGHT
        // NOW, so it must beat a perfectly well-formed build-time stamp. package-release.sh
        // only removes its stamp via an EXIT trap, so an interrupted run leaves a stale
        // (but syntactically valid) sidecar behind that must never win.
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
        // The gitlink exists and is readable but carries no worktree sha (plain gitdir
        // pointer). That must FALL THROUGH to the sidecar, not return null — the bug the
        // pre-TWO-25194 early `return null` introduced once gitlink moved to first place.
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
        // An unreadable gitlink file must also fall through rather than hard-null.
        $dir = self::makeTempModuleDir();
        $gitlink = $dir . '/.git';
        file_put_contents($gitlink, "gitdir: ../../.git/worktrees/fbdc80b92070eded9a2acbef222da0d55ac4af48\n");
        $sidecar = $dir . '/.two-deployed-commit';
        file_put_contents($sidecar, "1234abc\n");
        @chmod($gitlink, 0000);

        // Skip the assertion when running as a user that bypasses mode bits (e.g. root
        // in CI containers), where the file stays readable no matter the chmod.
        if (!is_readable($gitlink)) {
            TinyAssert::same('1234abc', self::callCommitHash($gitlink, $sidecar));
        }

        @chmod($gitlink, 0644);
        self::removeDir($dir);
    }

    private static function testEmptySidecarFallsBackToGitlinkFile(): void
    {
        // The staging sidecar is currently written as a 0-byte file by broken deploy
        // infra; that must NOT short-circuit into an empty/absent hash.
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
        // A gitlink pointing at a plain (non-worktree) gitdir carries no sha, and with no
        // sidecar to fall through to there is nothing left to resolve.
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

/**
 * Harness where no deployed commit can be resolved (no sidecar, no .git).
 */
final class TwopaymentClientVersionNoShaHarness extends TwopaymentTestHarness
{
    protected function getTwoDeployedCommitHash($git_dir = null, $sidecar_file = null)
    {
        return null;
    }
}

/**
 * Harness where the deployed commit resolves to a known short sha.
 */
final class TwopaymentClientVersionWithShaHarness extends TwopaymentTestHarness
{
    protected function getTwoDeployedCommitHash($git_dir = null, $sidecar_file = null)
    {
        return 'fbdc80b';
    }
}
