<?php

namespace BB\ContentPull;

/**
 * Compares a remote manifest with the local files in scope.
 *
 * Both sides must already be filtered with the same Filter.
 */
final class Diff
{
    /**
     * @param array<string, array> $remote entries of the source manifest (with sha1)
     * @param array<string, array> $local  local entries (sha1 may be null)
     * @param callable             $localHash fn(string $path): ?string, called lazily —
     *                                        only when sizes match
     * @return array{new: array, changed: array, deleted: array, skipped: array, unchanged: int}
     */
    public static function compute(array $remote, array $local, Options $options, callable $localHash): array
    {
        $result = [
            'new'       => [],
            'changed'   => [],
            'deleted'   => [],
            'skipped'   => [],
            'unchanged' => 0,
        ];

        foreach ($remote as $path => $entry) {
            if (
                $options->maxSize !== null &&
                Paths::isText($path) === false &&
                $entry['size'] > $options->maxSize
            ) {
                $result['skipped'][] = $entry + ['reason' => 'size'];
                continue;
            }

            $existing = $local[$path] ?? null;

            if ($existing === null) {
                $result['new'][] = $entry;
                continue;
            }

            if ($options->missingOnly === true) {
                $result['unchanged']++;
                continue;
            }

            if ($existing['size'] !== $entry['size']) {
                $result['changed'][] = $entry;
                continue;
            }

            $hash = $existing['sha1'] ?? $localHash($path);

            if ($entry['sha1'] === null || $hash !== $entry['sha1']) {
                $result['changed'][] = $entry;
                continue;
            }

            $result['unchanged']++;
        }

        if ($options->delete === true) {
            foreach ($local as $path => $entry) {
                if (isset($remote[$path]) === false) {
                    $result['deleted'][] = $entry;
                }
            }
        }

        return $result;
    }

    /**
     * Counts per category, e.g. for log lines and the CLI summary.
     *
     * @return array{new: int, changed: int, deleted: int, skipped: int, unchanged: int, bytes: int}
     */
    public static function summary(array $diff): array
    {
        $bytes = 0;

        foreach ([...$diff['new'], ...$diff['changed']] as $entry) {
            $bytes += $entry['size'];
        }

        return [
            'new'       => count($diff['new']),
            'changed'   => count($diff['changed']),
            'deleted'   => count($diff['deleted']),
            'skipped'   => count($diff['skipped']),
            'unchanged' => $diff['unchanged'],
            'bytes'     => $bytes,
        ];
    }
}
