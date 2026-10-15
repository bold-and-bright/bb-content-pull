<?php

namespace BB\ContentPull;

/**
 * Path rules shared by source and target.
 *
 * Manifest paths are relative and always start with their root
 * ("content/1_about/default.de.txt", "accounts/abc123/user.txt"). Every path
 * that crosses the wire — in either direction — goes through normalize()
 * before it touches the filesystem.
 */
final class Paths
{
    public const CONTENT  = 'content';
    public const ACCOUNTS = 'accounts';

    /**
     * File names that are never transferred, deleted or listed. `.logins` is
     * Kirby's per-installation login throttle in site/accounts.
     */
    public const EXCLUDED_NAMES = ['.lock', '.DS_Store', '.gitkeep', '.logins'];

    /** Kirby 5 stores unsaved panel changes in this subfolder. */
    public const CHANGES_DIR = '_changes';

    /** Prefix of in-flight downloads; renamed into place once complete. */
    public const TEMP_PREFIX = '.bbpull-';

    /**
     * Returns the path if it is a safe, relative manifest path below one of
     * the allowed roots, otherwise null.
     *
     * @param array<int, string> $roots
     */
    public static function normalize(string $path, array $roots = [self::CONTENT]): ?string
    {
        if ($path === '' || strlen($path) > 1024) {
            return null;
        }

        // Control characters (incl. NUL), backslashes and drive letters have
        // no business in a manifest path.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
            return null;
        }

        if ($path[0] === '/' || preg_match('/^[a-zA-Z]:/', $path) === 1) {
            return null;
        }

        $segments = explode('/', $path);

        // At least root + file name
        if (count($segments) < 2) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        if (in_array($segments[0], $roots, true) === false) {
            return null;
        }

        return $path;
    }

    /**
     * Maps a normalized manifest path to an absolute path inside its root
     * directory. Returns null if the root is unknown or the path would leave
     * the root (e.g. through a symlinked directory).
     *
     * @param array<string, string> $rootDirs e.g. ['content' => '/var/www/content']
     */
    public static function resolve(string $path, array $rootDirs): ?string
    {
        $path = self::normalize($path, array_keys($rootDirs));

        if ($path === null) {
            return null;
        }

        [$root, $relative] = explode('/', $path, 2);
        $rootDir = realpath($rootDirs[$root]);

        if ($rootDir === false) {
            return null;
        }

        $absolute = $rootDir . '/' . $relative;

        // Find the nearest existing ancestor and make sure it really lives
        // inside the root; this catches symlinks pointing elsewhere.
        $existing = $absolute;
        while (file_exists($existing) === false && is_link($existing) === false) {
            $existing = dirname($existing);
        }

        $real = realpath($existing);

        if ($real === false || ($real !== $rootDir && str_starts_with($real, $rootDir . '/') === false)) {
            return null;
        }

        // The file itself must not be a symlink either
        if (is_link($absolute) === true) {
            return null;
        }

        return $absolute;
    }

    /**
     * Files that are never transferred: lock files, OS clutter, temp files of
     * this plugin and — unless requested — Kirby 5 change versions.
     */
    public static function isExcluded(string $path, bool $includeChanges = false): bool
    {
        $name = basename($path);

        if (in_array($name, self::EXCLUDED_NAMES, true) === true) {
            return true;
        }

        if (str_starts_with($name, self::TEMP_PREFIX) === true) {
            return true;
        }

        if ($includeChanges === false && in_array(self::CHANGES_DIR, explode('/', $path), true) === true) {
            return true;
        }

        return false;
    }

    public static function root(string $path): string
    {
        return explode('/', $path, 2)[0];
    }

    public static function isText(string $path): bool
    {
        return str_ends_with($path, '.txt');
    }

    /**
     * Derives the Kirby page id from the folder a content file lives in:
     * sorting numbers and `_drafts` / `_changes` folders are stripped.
     *
     * content/site.de.txt                         → ''  (site)
     * content/1_about/team.jpg                    → 'about'
     * content/1_about/_drafts/jobs/job.de.txt     → 'about/jobs'
     * content/20240101_blog/_changes/post.de.txt  → 'blog'
     *
     * Returns null for paths outside the content root.
     */
    public static function pageId(string $path): ?string
    {
        $segments = explode('/', $path);

        if ($segments[0] !== self::CONTENT) {
            return null;
        }

        // drop root and file name
        $folders = array_slice($segments, 1, -1);
        $id      = [];

        foreach ($folders as $folder) {
            if ($folder === '_drafts' || $folder === self::CHANGES_DIR) {
                continue;
            }

            $id[] = preg_replace('/^\d+_/', '', $folder);
        }

        return implode('/', $id);
    }

    /**
     * Normalizes a page id given by a user: slashes trimmed, "site" and "/"
     * address the site itself (empty id).
     */
    public static function normalizePageId(?string $page): ?string
    {
        if ($page === null) {
            return null;
        }

        $page = trim($page, " \t/");

        if ($page === 'site') {
            return '';
        }

        return $page;
    }

    /**
     * Language code of a text file ("default.de.txt" → "de",
     * "image.jpg.en.txt" → "en"), or null for files without one.
     *
     * With known languages the code is matched exactly, which avoids reading
     * "photo.ai.txt" as Afar. Without, a two-letter code (optionally with a
     * region) is assumed.
     *
     * @param array<int, string> $known
     */
    public static function language(string $path, array $known = []): ?string
    {
        if (self::isText($path) === false) {
            return null;
        }

        $name = substr(basename($path), 0, -4);

        if ($known !== []) {
            foreach ($known as $code) {
                if (str_ends_with($name, '.' . $code) === true) {
                    return $code;
                }
            }

            return null;
        }

        if (preg_match('/\.([a-z]{2}(?:[-_][a-zA-Z0-9]+)?)$/', $name, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
