<?php

declare(strict_types=1);

/**
 * TWO-26082 - every `%parameter%` the module's service files reference must
 * exist in the container that loads that file on PrestaShop 1.7.6, the oldest
 * version the module supports. On 1.7.6 config/admin/ and config/front/ are
 * compiled into the legacy containers every back-office and storefront
 * controller builds, so a missing parameter there takes down every page, not
 * just the service. config/services.yml is loaded by the Symfony kernel only.
 */
final class ServiceConfigParametersSpec
{
    /** What PS 1.7.6's ContainerParametersExtension sets on the legacy admin and front containers. */
    private const LEGACY_CONTAINER = [
        'kernel.active_modules',
        'kernel.bundles',
        'kernel.cache_dir',
        'kernel.debug',
        'kernel.environment',
        'kernel.name',
        'kernel.root_dir',
    ];

    /** Kernel parameters present on the 1.7.6 Symfony kernel and still present on 9.x. */
    private const SYMFONY_KERNEL = ['kernel.project_dir', 'kernel.cache_dir', 'kernel.debug', 'kernel.environment'];

    public static function runAll(): void
    {
        $root = dirname(__DIR__) . '/config';
        $failures = self::check($root);
        TinyAssert::same([], $failures, implode("\n  - ", array_merge(['service files reference parameters PS 1.7.6 does not define:'], $failures)));

        foreach (self::rows() as $row) {
            self::assertRow(...$row);
        }
    }

    // file under config/, its content, expected failure count, description
    private static function rows(): array
    {
        $projectDir = "services:\n  x:\n    file: '%kernel.project_dir%/modules/twopayment/x.php'\n";

        return [
            ['admin/services.yml', $projectDir, 1, 'kernel.project_dir in config/admin/ is refused (1.7.6 legacy admin container)'],
            ['front/services.yml', $projectDir, 1, 'kernel.project_dir in config/front/ is refused (1.7.6 legacy front container)'],
            ['services.yml', $projectDir, 0, 'kernel.project_dir in config/services.yml is allowed (Symfony kernel)'],
            ['services.yml', "parameters:\n  a: '%made.up%'\n", 1, 'an unknown parameter in config/services.yml is refused'],
            ['admin/services.yml', "services:\n  x:\n    arguments: ['100%%']\n", 0, 'an escaped percent is not a parameter'],
        ];
    }

    private static function assertRow(string $file, string $content, int $expected, string $description): void
    {
        $root = sys_get_temp_dir() . '/two-service-config-' . getmypid();
        @mkdir(dirname($root . '/' . $file), 0777, true);
        file_put_contents($root . '/' . $file, $content);
        try {
            TinyAssert::same($expected, count(self::check($root)), $description);
        } finally {
            unlink($root . '/' . $file);
            @rmdir(dirname($root . '/' . $file));
            @rmdir($root);
        }
    }

    /** @return string[] one line per reference the loading container would not resolve */
    private static function check(string $root): array
    {
        $failures = [];
        $files = array_merge(glob($root . '/*.yml') ?: [], glob($root . '/*/*.yml') ?: []);
        foreach ($files as $path) {
            $relative = substr($path, strlen($root) + 1);
            $allowed = strpos($relative, '/') === false ? self::SYMFONY_KERNEL : self::LEGACY_CONTAINER;
            // `%%` is YAML-DI's escaped percent, so strip it before matching `%name%`.
            preg_match_all('/%([A-Za-z0-9_.]+)%/', str_replace('%%', '', (string) file_get_contents($path)), $matches);
            foreach (array_unique($matches[1]) as $parameter) {
                if (!in_array($parameter, $allowed, true)) {
                    $failures[] = 'config/' . $relative . ' references %' . $parameter . '%';
                }
            }
        }

        return $failures;
    }
}
