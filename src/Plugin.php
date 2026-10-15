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

    /**
     * Directory for the plugin's own state below the cache root; kept when
     * the pull clears the cache.
     */
    public static function stateDir(App $kirby): string
    {
        return $kirby->root('cache') . '/bb-content-pull';
    }

    /**
     * Hash cache (`bb.contentpull.hashCache`, default on).
     */
    public static function hashCache(App $kirby): ?HashCache
    {
        if (self::option('hashCache', true) === false) {
            return null;
        }

        return new HashCache(self::stateDir($kirby) . '/hashes.json');
    }

    /**
     * Shared file cache of pulling environments (`bb.contentpull.cache`,
     * a directory; off when not set).
     */
    public static function objectCache(): ?ObjectCache
    {
        $dir = self::option('cache');

        return is_string($dir) === true && trim($dir) !== '' ? new ObjectCache(rtrim(trim($dir), '/')) : null;
    }

    public static function logFile(App $kirby): string
    {
        // The logs root exists since Kirby 4; fall back to site/logs
        return ($kirby->root('logs') ?? $kirby->root('site') . '/logs') . '/content-pull.log';
    }
}
