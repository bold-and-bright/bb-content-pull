<?php

namespace BB\ContentPull;

/**
 * Remembers SHA-1 hashes by path, size and mtime, so unchanged files are not
 * hashed again. Saves the source from re-reading its whole media library on
 * every manifest request, and the target from re-hashing local files on every
 * pull.
 */
final class HashCache
{
    /** @var array<string, array{0: int, 1: int, 2: string}> path => [size, mtime, sha1] */
    private array $entries = [];
    private bool $dirty = false;

    public function __construct(private readonly ?string $file)
    {
        if ($file !== null && is_file($file) === true) {
            $data = json_decode((string) file_get_contents($file), true);
            $this->entries = is_array($data) === true ? $data : [];
        }
    }

    public function get(string $path, string $file, int $size, int $mtime): ?string
    {
        $cached = $this->entries[$path] ?? null;

        if (is_array($cached) === true && $cached[0] === $size && $cached[1] === $mtime) {
            return $cached[2];
        }

        $sha1 = sha1_file($file);

        if ($sha1 === false) {
            return null;
        }

        $this->entries[$path] = [$size, $mtime, $sha1];
        $this->dirty = true;

        return $sha1;
    }

    public function save(): void
    {
        if ($this->file === null || $this->dirty === false) {
            return;
        }

        $dir = dirname($this->file);

        if (is_dir($dir) === false) {
            @mkdir($dir, 0775, true);
        }

        // Concurrent requests may save at the same time: write and rename, the
        // last writer wins and nobody reads half a file
        $temp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temp, json_encode($this->entries, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($temp, $this->file);
        }

        @unlink($temp);
        $this->dirty = false;
    }
}
