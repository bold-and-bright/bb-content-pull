<?php

namespace BB\ContentPull;

use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Http\Response;
use Throwable;
use ZipArchive;

/**
 * Source side: /bb-content-pull/manifest|file|archive.
 *
 * Only active with `bb.contentpull.serve => true`. Every request needs either
 * the configured token or a session token from /bb-content-pull/login (Kirby
 * credentials of a user with an allowed role) as Bearer or
 * X-Content-Pull-Token header.
 */
final class Server
{
    /** The Kirby user of the current request; null for the shared token */
    private static ?User $user = null;

    /**
     * POST /bb-content-pull/login with the Kirby credentials as HTTP Basic auth
     * (or X-Content-Pull-Auth: base64("email:password")) returns a session
     * token for the following requests.
     */
    public static function login(): Response
    {
        if (Plugin::isSource() === false) {
            return self::error('Not found', 404);
        }

        $kirby       = App::instance();
        $credentials = self::requestCredentials();

        if ($credentials === null) {
            return self::error('Credentials missing', 401, ['WWW-Authenticate' => 'Basic realm="content-pull"']);
        }

        try {
            // Kirby's own check incl. rate limiting and the delay on failure
            $user = $kirby->auth()->validatePassword($credentials[0], $credentials[1]);
        } catch (Throwable) {
            return self::error('Invalid login', 401);
        }

        if (self::roleAllowed($user) === false) {
            return self::error('Your role may not pull content (bb.contentpull.roles)', 403);
        }

        $expires = time() + (int) Plugin::option('sessionTtl', 7200);

        return Response::json([
            'token'   => SessionToken::create($user->id(), $expires, self::sessionKey($user)),
            'expires' => $expires,
            'user'    => $user->email(),
            'role'    => $user->role()->name(),
        ], 200, false, ['Cache-Control' => 'no-store']);
    }

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
            return self::error('Accounts are not served (bb.contentpull.accounts) or not to your role (admins only)', 403);
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
            return self::error('Accounts are not served (bb.contentpull.accounts) or not to your role (admins only)', 403);
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

        self::$user = null;

        $expected = Plugin::token();
        $given    = self::requestToken();

        if ($given !== null && $expected !== null && hash_equals($expected, $given) === true) {
            return null;
        }

        if ($given !== null && SessionToken::looksLike($given) === true) {
            $user = self::sessionUser($given);

            if ($user !== null) {
                self::$user = $user;
                return null;
            }
        }

        return self::error('Unauthorized', 401, ['WWW-Authenticate' => 'Bearer']);
    }

    private static function sessionUser(string $token): ?User
    {
        $id   = SessionToken::userId($token);
        $user = $id !== null ? App::instance()->user($id) : null;

        if ($user === null || self::roleAllowed($user) === false) {
            return null;
        }

        return SessionToken::verify($token, self::sessionKey($user)) === true ? $user : null;
    }

    /**
     * Derived from the password hash: a new password revokes all tokens.
     */
    private static function sessionKey(User $user): string
    {
        return hash('sha256', implode('|', [
            'bb-content-pull',
            $user->id(),
            (string) $user->password(),
            App::instance()->root('index'),
        ]), true);
    }

    private static function roleAllowed(User $user): bool
    {
        $roles = (array) Plugin::option('roles', ['admin']);

        return in_array($user->role()->name(), $roles, true);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function requestCredentials(): ?array
    {
        $request = App::instance()->request();
        $auth    = $request->auth();

        if ($auth instanceof \Kirby\Http\Request\Auth\BasicAuth && $auth->username() !== null) {
            return [$auth->username(), (string) $auth->password()];
        }

        // Fallback for hosts and proxies that keep the Authorization header
        $fallback = base64_decode((string) $request->header('X-Content-Pull-Auth'), true);

        if (is_string($fallback) === true && str_contains($fallback, ':') === true) {
            return explode(':', $fallback, 2);
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

    /**
     * Accounts contain password hashes: served only when enabled, and to a
     * Kirby user only if they are an admin.
     */
    private static function accountsAllowed(): bool
    {
        if (Plugin::option('accounts', false) !== true) {
            return false;
        }

        return self::$user === null || self::$user->isAdmin() === true;
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
