<?php

namespace BB\ContentPull;

use Kirby\Cms\App;

/**
 * Configuration access and environment checks.
 *
 * Options live under `bb.contentpull.*` (set as flat dot keys in config.php).
 */
final class Plugin
{
    public static function option(string $key, mixed $default = null): mixed
    {
        return App::instance()->option('bb.contentpull.' . $key, $default);
    }

    /** This environment serves its content to others. */
    public static function isSource(): bool
    {
        return self::option('serve', false) === true;
    }

    public static function source(): ?string
    {
        $source = self::option('source');

        return is_string($source) === true && trim($source) !== '' ? rtrim(trim($source), '/') : null;
    }

    public static function token(): ?string
    {
        $token = self::option('token');

        return is_string($token) === true && $token !== '' ? $token : null;
    }

    /**
     * @return array<string, string>
     */
    public static function rootDirs(App $kirby): array
    {
        return [
            Paths::CONTENT  => $kirby->root('content'),
            Paths::ACCOUNTS => $kirby->root('accounts'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function languages(App $kirby): array
    {
        return $kirby->multilang() === true ? $kirby->languages()->codes() : [];
    }

    public static function logFile(App $kirby): string
    {
        // The logs root exists since Kirby 4; fall back to site/logs
        return ($kirby->root('logs') ?? $kirby->root('site') . '/logs') . '/content-pull.log';
    }
}
