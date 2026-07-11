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
        self::testSidecarFileTakesPrecedenceOverGitDir();
        self::testInvalidSidecarFallsBackToGitDir();
        self::testDeployedAtLabelMatchesModuleFileMtime();
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

    private static function testSidecarFileTakesPrecedenceOverGitDir(): void
    {
        // Deploy-time sidecar file (written by the git-sync materialise loop) must win
        // over a co-located .git directory when both exist.
        $git = self::makeTempGitDir();
        file_put_contents($git . '/HEAD', "ref: refs/heads/staging\n");
        mkdir($git . '/refs/heads', 0777, true);
        file_put_contents($git . '/refs/heads/staging', "abcdef0123456789abcdef0123456789abcdef01\n");
        $sidecar = dirname($git) . '/.two-deployed-commit';
        file_put_contents($sidecar, "1234abc\n");

        TinyAssert::same('1234abc', self::callCommitHash($git, $sidecar));
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

    private static function testDeployedAtLabelMatchesModuleFileMtime(): void
    {
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoDeployedAtLabel');
        $label = $method->invoke($module);

        $mtime = filemtime(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::same(date('Y-m-d H:i:s', $mtime), $label);
    }
}
