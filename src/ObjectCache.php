<?php

namespace BB\ContentPull;

/**
 * Content-addressed file cache shared by several pulling environments on the
 * same server (stage and PR previews): every file is stored once under its
 * SHA-1 and only fetched from the source the first time any of them needs it.
 *
 * Layout: <dir>/ab/abcdef…  (first two hex chars as subfolder)
 */
final class ObjectCache
{
    public function __construct(private readonly string $dir)
    {
    }

    public function path(string $sha1): string
    {
        return $this->dir . '/' . substr($sha1, 0, 2) . '/' . $sha1;
    }

    public function has(?string $sha1): bool
    {
        return $sha1 !== null && is_file($this->path($sha1)) === true;
    }

    /**
     * Copies a verified file into the cache. Failures are ignored: the cache
     * is an optimization, never a reason for a pull to fail.
     */
    public function store(string $sha1, string $file): void
    {
        $target = $this->path($sha1);

        if (is_file($target) === true) {
            return;
        }

        $dir = dirname($target);

        if (is_dir($dir) === false && @mkdir($dir, 0775, true) === false && is_dir($dir) === false) {
            return;
        }

        $temp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@copy($file, $temp) === true) {
            @rename($temp, $target);
        }

        @unlink($temp);
    }

    /**
     * Drops an entry that failed verification.
     */
    public function forget(string $sha1): void
    {
        @unlink($this->path($sha1));
    }
}
