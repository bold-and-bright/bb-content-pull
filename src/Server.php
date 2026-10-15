<?php

namespace BB\ContentPull;

use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;
use ZipArchive;

/**
 * Source side: /bb-content-pull/manifest|file|archive.
 *
 * Only active with `bb.contentpull.serve => true`; every request needs the
 * configured token as Bearer (or X-Content-Pull-Token) header.
 */
final class Server
{
    public static function manifest(): Response
    {
        if (($denied = self::guard()) !== null) {
            return $denied;
        }

        $kirby = App::instance();

        try {
            $options = self::options();
        } catch (Throwable $e) {
            return self::error($e->getMessage(), 400);
        }

        if ($options->includesAccounts() === true && self::accountsAllowed() === false) {
            return self::error('Accounts are not served by this source (bb.contentpull.accounts)', 403);
        }

        @set_time_limit(0);

        $filter = new Filter($options, Plugin::languages($kirby));
        $files  = Manifest::scan(
            Plugin::rootDirs($kirby),
            $filter,
            hash: $kirby->request()->get('hash') !== '0',
            cache: Plugin::hashCache($kirby),
        );

        return Response::json([
            'generated' => time(),
            'languages' => Plugin::languages($kirby),
            'files'     => array_values($files),
        ], 200, false, ['Cache-Control' => 'no-store']);
    }

    public static function file(): Response
    {
        if (($denied = self::guard()) !== null) {
            return $denied;
        }

        $kirby = App::instance();
        $roots = self::accountsAllowed() === true ? [Paths::CONTENT, Paths::ACCOUNTS] : [Paths::CONTENT];
        $path  = Paths::normalize((string) $kirby->request()->get('path', ''), $roots);

        // _changes files may be requested (--include-changes); lock files,
        // OS clutter and temp files never
        if ($path === null || Paths::isExcluded($path, includeChanges: true) === true) {
            return self::error('Invalid path', 400);
        }

        $file = Paths::resolve($path, Plugin::rootDirs($kirby));

        if ($file === null || is_file($file) === false) {
            return self::error('Not found', 404);
        }

        self::stream($file, basename($file));
    }

    public static function archive(): Response
    {
        if (($denied = self::guard()) !== null) {
            return $denied;
        }

        if (class_exists(ZipArchive::class) === false) {
            return self::error('The PHP zip extension is not available on the source', 501);
        }

        $kirby = App::instance();

        try {
            $options = self::options();
            $maxSize = Size::parse($kirby->request()->get('max-size'));
        } catch (Throwable $e) {
            return self::error($e->getMessage(), 400);
        }

        if ($options->includesAccounts() === true && self::accountsAllowed() === false) {
            return self::error('Accounts are not served by this source (bb.contentpull.accounts)', 403);
        }

        @set_time_limit(0);

        $rootDirs = Plugin::rootDirs($kirby);
        $files    = Manifest::scan($rootDirs, new Filter($options, Plugin::languages($kirby)), hash: false);
        $archive  = tempnam(sys_get_temp_dir(), 'bbpull');
        $zip      = new ZipArchive();

        if ($zip->open($archive, ZipArchive::OVERWRITE) !== true) {
            return self::error('Could not create the archive', 500);
        }

        foreach ($files as $path => $entry) {
            if ($maxSize !== null && Paths::isText($path) === false && $entry['size'] > $maxSize) {
                continue;
            }

            $file = Paths::resolve($path, $rootDirs);

            if ($file === null) {
                continue;
            }

            $zip->addFile($file, $path);

            // Media is already compressed — storing is much faster
            if (Paths::isText($path) === false) {
                $zip->setCompressionName($path, ZipArchive::CM_STORE);
            }
        }

        // An empty archive is not written to disk at all
        if ($zip->numFiles === 0) {
            $zip->close();
            $zip->open($archive, ZipArchive::OVERWRITE);
            $zip->addFromString('.empty', '');
        }

        $zip->close();

        self::stream($archive, 'content.zip', delete: true);
    }

    /**
     * Returns an error response or null if the request may pass.
     */
    private static function guard(): ?Response
    {
        if (Plugin::isSource() === false) {
            return self::error('Not found', 404);
        }

        $expected = Plugin::token();
        $given    = self::requestToken();

        if ($expected === null || $given === null || hash_equals($expected, $given) === false) {
            return self::error('Unauthorized', 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return null;
    }

    private static function requestToken(): ?string
    {
        $request = App::instance()->request();
        $header  = $request->header('Authorization')
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if (is_string($header) === true && preg_match('/^Bearer\s+(\S+)$/i', trim($header), $match) === 1) {
            return $match[1];
        }

        $fallback = $request->header('X-Content-Pull-Token');

        return is_string($fallback) === true && $fallback !== '' ? $fallback : null;
    }

    private static function options(): Options
    {
        // Diff-only options are meaningless here and ignored
        $query = App::instance()->request()->query()->toArray();

        return Options::fromArray(array_intersect_key($query, array_flip([
            'scope', 'page', 'recursive', 'lang', 'accounts', 'include-changes',
        ])));
    }

    private static function accountsAllowed(): bool
    {
        return Plugin::option('accounts', false) === true;
    }

    private static function error(string $message, int $code, array $headers = []): Response
    {
        return Response::json(['error' => $message], $code, false, $headers + ['Cache-Control' => 'no-store']);
    }

    /**
     * Streams a file without loading it into memory and ends the request.
     * Always sent as download so nothing (SVG, HTML) is ever rendered.
     */
    private static function stream(string $file, string $name, bool $delete = false): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="' . addcslashes($name, '"\\') . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        readfile($file);

        if ($delete === true) {
            @unlink($file);
        }

        exit;
    }
}
