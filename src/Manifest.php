<?php

namespace BB\ContentPull;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Lists the files below the content (and optionally accounts) root.
 *
 * Entry shape: ['path' => 'content/…', 'sha1' => '…'|null, 'size' => int, 'mtime' => int]
 */
final class Manifest
{
    /**
     * @param array<string, string> $rootDirs e.g. ['content' => '/var/www/content']
     * @param bool                  $hash     false leaves sha1 empty (cheap listing)
     * @return array<string, array{path: string, sha1: ?string, size: int, mtime: int}>
     */
    public static function scan(array $rootDirs, Filter $filter, bool $hash = true): array
    {
        $entries = [];

        foreach ($filter->roots() as $root) {
            $dir = $rootDirs[$root] ?? null;

            if ($dir === null || is_dir($dir) === false) {
                continue;
            }

            foreach (self::files($dir) as $file) {
                $relative = substr($file->getPathname(), strlen($dir) + 1);
                $path     = $root . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);

                if ($filter->accepts($path) === false) {
                    continue;
                }

                $entries[$path] = [
                    'path'  => $path,
                    'sha1'  => $hash === true ? sha1_file($file->getPathname()) : null,
                    'size'  => $file->getSize(),
                    'mtime' => $file->getMTime(),
                ];
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * Validates and indexes a manifest received from a source. Entries with
     * unsafe paths are dropped and reported instead of trusted.
     *
     * @param array<int, mixed>  $files
     * @param array<int, string> $roots
     * @param array<int, string> $rejected collects rejected paths
     * @return array<string, array{path: string, sha1: ?string, size: int, mtime: int}>
     */
    public static function fromRemote(array $files, array $roots, array &$rejected = []): array
    {
        $entries = [];

        foreach ($files as $file) {
            $path = is_array($file) === true && is_string($file['path'] ?? null) === true ? $file['path'] : null;

            if ($path === null || Paths::normalize($path, $roots) === null) {
                $rejected[] = is_string($path) === true ? $path : '(invalid entry)';
                continue;
            }

            $sha1 = $file['sha1'] ?? null;

            $entries[$path] = [
                'path'  => $path,
                'sha1'  => is_string($sha1) === true && preg_match('/^[a-f0-9]{40}$/', $sha1) === 1 ? $sha1 : null,
                'size'  => (int) ($file['size'] ?? 0),
                'mtime' => (int) ($file['mtime'] ?? 0),
            ];
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private static function files(string $dir): iterable
    {
        // Symlinked directories are not followed (no FOLLOW_SYMLINKS) and
        // symlinked files are skipped: only real content leaves the server.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() === true && $file->isLink() === false) {
                yield $file;
            }
        }
    }
}
