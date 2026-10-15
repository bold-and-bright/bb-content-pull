<?php

namespace BB\ContentPull;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The pull itself, used by the CLI command.
 *
 *   plan()  fetches the source manifest and compares it with the local files
 *   run()   plans, then downloads, deletes and clears caches
 *
 * Every write goes to a temp file next to its target and is renamed into
 * place after the checksum matched, so an aborted run never leaves half a
 * file behind and running the same pull twice is a no-op.
 */
final class Puller
{
    private Log $log;

    /** Files taken from the shared cache in the current run */
    private int $cacheHits = 0;

    public function __construct(
        private readonly App $kirby,
        private readonly Client $client,
        string $actor = 'cli',
        private readonly ?ObjectCache $cache = null,
    ) {
        $this->log = new Log(Plugin::logFile($kirby), $actor);
    }

    public static function fromConfig(App $kirby, string $actor = 'cli'): self
    {
        $source = Plugin::source();
        $token  = Plugin::token();

        if ($source === null) {
            throw new RuntimeException('No source configured (bb.contentpull.source)');
        }

        if ($token === null) {
            throw new RuntimeException('No token configured (bb.contentpull.token)');
        }

        if (rtrim($kirby->url('index'), '/') === $source) {
            throw new RuntimeException('The source is this installation itself — refusing to pull');
        }

        $verify = Plugin::option('verify', true);

        return new self($kirby, new Client(
            source: $source,
            token: $token,
            timeout: (int) Plugin::option('timeout', 120),
            ca: $verify === false ? false : Remote::CA_INTERNAL,
        ), $actor, Plugin::objectCache());
    }

    public function client(): Client
    {
        return $this->client;
    }

    /**
     * @return array{source: string, options: array, diff: array, summary: array}
     */
    public function plan(Options $options): array
    {
        $filter   = new Filter($options, Plugin::languages($this->kirby));
        $rootDirs = Plugin::rootDirs($this->kirby);
        $manifest = $this->client->manifest($options);

        $rejected = [];
        $remote   = Manifest::fromRemote($manifest['files'], $filter->roots(), $rejected);

        // Never rely on the source having applied the filter
        $remote = array_filter($remote, fn (array $entry) => $filter->accepts($entry['path']));
        $local  = Manifest::scan($rootDirs, $filter, hash: false);
        $hashes = Plugin::hashCache($this->kirby);

        // Local files are only hashed when their size matches the remote one,
        // and then through the hash cache: unchanged files are not re-read
        $diff = Diff::compute(
            $remote,
            $local,
            $options,
            function (string $path) use ($rootDirs, $local, $hashes): ?string {
                $file = Paths::resolve($path, $rootDirs);

                if ($file === null) {
                    return null;
                }

                return $hashes !== null
                    ? $hashes->get($path, $file, $local[$path]['size'], $local[$path]['mtime'])
                    : (sha1_file($file) ?: null);
            }
        );

        $hashes?->save();

        foreach ($rejected as $path) {
            $diff['skipped'][] = ['path' => $path, 'size' => 0, 'sha1' => null, 'mtime' => 0, 'reason' => 'invalid path'];
        }

        return [
            'source'  => $this->client->source(),
            'options' => $options->toArray(),
            'diff'    => $diff,
            'summary' => Diff::summary($diff),
        ];
    }

    /**
     * @param callable|null $progress fn(string $action, string $path, ?string $error):
     *                                action is "write", "delete" or "fail"
     * @return array{source: string, options: array, diff: array, summary: array, result: ?array}
     */
    public function run(Options $options, ?callable $progress = null): array
    {
        $lock = $this->lock();
        $started = microtime(true);

        try {
            $this->log->write('START source=' . $this->client->source() . ' ' . $options->describe());

            $plan = $this->plan($options);
            $plan['result'] = null;

            if ($options->dryRun === true) {
                $this->log->write('DRY-RUN ' . $this->formatSummary($plan['summary']));

                return $plan;
            }

            $this->cleanupTempFiles();

            $result = ['written' => [], 'deleted' => [], 'failed' => []];
            $report = function (string $action, string $path, ?string $error = null) use (&$result, $progress): void {
                match ($action) {
                    'write'  => $result['written'][] = $path,
                    'delete' => $result['deleted'][] = $path,
                    'fail'   => $result['failed'][]  = ['path' => $path, 'error' => $error],
                };

                $this->log->write(match ($action) {
                    'write'  => '  + ' . $path,
                    'delete' => '  - ' . $path,
                    'fail'   => '  ! ' . $path . ': ' . $error,
                });

                if ($progress !== null) {
                    $progress($action, $path, $error);
                }
            };

            $downloads = $this->installFromCache(
                [...$plan['diff']['new'], ...$plan['diff']['changed']],
                $report
            );

            if ($downloads !== []) {
                $options->archive === true
                    ? $this->downloadArchive($options, $downloads, $report)
                    : $this->downloadFiles($downloads, $report);
            }

            $this->delete($plan['diff']['deleted'], $report);

            if ($result['written'] !== [] || $result['deleted'] !== []) {
                $this->clearCaches();
            }

            $plan['result'] = $result + [
                'cached'   => $this->cacheHits,
                'duration' => round(microtime(true) - $started, 1),
            ];

            $this->log->write(sprintf(
                'DONE written=%d (cached=%d) deleted=%d failed=%d duration=%ss',
                count($result['written']),
                $this->cacheHits,
                count($result['deleted']),
                count($result['failed']),
                $plan['result']['duration']
            ));

            return $plan;
        } catch (Throwable $e) {
            $this->log->write('ERROR ' . $e->getMessage());
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Installs every entry the shared cache already holds and returns the
     * rest, which has to come from the source.
     *
     * @param array<int, array> $entries
     * @return array<int, array>
     */
    private function installFromCache(array $entries, callable $report): array
    {
        $this->cacheHits = 0;

        if ($this->cache === null) {
            return $entries;
        }

        $missing = [];

        foreach ($entries as $entry) {
            if ($this->cache->has($entry['sha1']) === false) {
                $missing[] = $entry;
                continue;
            }

            try {
                $this->install($entry, file: $this->cache->path($entry['sha1']));
                $this->cacheHits++;
                $report('write', $entry['path']);
            } catch (Throwable) {
                // A damaged cache entry is dropped and fetched again
                $this->cache->forget($entry['sha1']);
                $missing[] = $entry;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, array> $entries
     */
    private function downloadFiles(array $entries, callable $report): void
    {
        foreach ($entries as $entry) {
            try {
                $this->install($entry, contents: $this->client->download($entry['path']));
                $report('write', $entry['path']);
            } catch (Throwable $e) {
                $report('fail', $entry['path'], $e->getMessage());
            }
        }
    }

    /**
     * Fetches the whole filtered set as one ZIP and takes only the entries
     * that differ. Entries are read by name — never extracted blindly — so a
     * crafted archive cannot write outside the content root.
     *
     * @param array<int, array> $entries
     */
    private function downloadArchive(Options $options, array $entries, callable $report): void
    {
        if (class_exists(ZipArchive::class) === false) {
            throw new RuntimeException('--archive needs the PHP zip extension');
        }

        $file = tempnam(sys_get_temp_dir(), 'bbpull');

        try {
            $this->client->archive($options, $file);

            $zip = new ZipArchive();

            if ($zip->open($file) !== true) {
                throw new RuntimeException('The source returned an invalid archive');
            }

            foreach ($entries as $entry) {
                try {
                    $contents = $zip->getFromName($entry['path']);

                    if ($contents === false) {
                        throw new RuntimeException('missing in archive');
                    }

                    $this->install($entry, contents: $contents);
                    $report('write', $entry['path']);
                } catch (Throwable $e) {
                    $report('fail', $entry['path'], $e->getMessage());
                }
            }

            $zip->close();
        } finally {
            F::remove($file);
        }
    }

    /**
     * Writes one file atomically: temp file next to the target, checksum
     * against the manifest, then rename. New downloads also go into the
     * shared cache.
     *
     * @param string|null $contents downloaded contents, or
     * @param string|null $file     a file to copy (cache entry)
     */
    private function install(array $entry, ?string $contents = null, ?string $file = null): void
    {
        $rootDirs = Plugin::rootDirs($this->kirby);

        // A fresh installation may not have site/accounts yet; resolve()
        // only accepts paths below an existing root
        Dir::make($rootDirs[Paths::root($entry['path'])]);

        $target = Paths::resolve($entry['path'], $rootDirs);

        if ($target === null) {
            throw new RuntimeException('unsafe target path');
        }

        $dir = dirname($target);
        Dir::make($dir);

        $temp = $dir . '/' . Paths::TEMP_PREFIX . bin2hex(random_bytes(6)) . '.tmp';

        try {
            $written = $file !== null
                ? copy($file, $temp)
                : file_put_contents($temp, (string) $contents, LOCK_EX) === strlen((string) $contents);

            if ($written === false) {
                throw new RuntimeException('could not write ' . $temp);
            }

            // Guards against files that changed on the source after the
            // manifest was built, truncated transfers and damaged cache entries
            if (sha1_file($temp) !== $entry['sha1']) {
                throw new RuntimeException('checksum mismatch — file changed on the source or transfer incomplete');
            }

            if ($file === null) {
                $this->cache?->store($entry['sha1'], $temp);
            }

            // Keep the source's modification time (Kirby uses it for
            // modified() dates and cache busting)
            if ($entry['mtime'] > 0) {
                @touch($temp, $entry['mtime']);
            }

            if (rename($temp, $target) === false) {
                throw new RuntimeException('could not move the file into place');
            }
        } finally {
            if (is_file($temp) === true) {
                @unlink($temp);
            }
        }
    }

    /**
     * @param array<int, array> $entries
     */
    private function delete(array $entries, callable $report): void
    {
        $rootDirs = Plugin::rootDirs($this->kirby);
        $dirs     = [];

        foreach ($entries as $entry) {
            $file = Paths::resolve($entry['path'], $rootDirs);

            if ($file === null || is_file($file) === false) {
                $report('fail', $entry['path'], 'cannot delete: unsafe path or not a file');
                continue;
            }

            if (F::remove($file) === true) {
                $dirs[dirname($file)] = Paths::root($entry['path']);
                $report('delete', $entry['path']);
            } else {
                $report('fail', $entry['path'], 'could not delete');
            }
        }

        // Remove page folders that became empty, bottom-up, never the root
        foreach ($dirs as $dir => $root) {
            $rootDir = realpath($rootDirs[$root]);

            while (
                $dir !== $rootDir &&
                str_starts_with($dir, $rootDir . '/') === true &&
                is_dir($dir) === true &&
                Dir::isEmpty($dir) === true
            ) {
                Dir::remove($dir);
                $dir = dirname($dir);
            }
        }
    }

    /**
     * Empties site/cache and media/ through Kirby's cache and filesystem
     * APIs; dotfiles (.gitkeep, .gitignore, .htaccess) stay.
     *
     * media/panel and media/plugins hold panel and plugin assets, not
     * content derivatives — removing them breaks the open panel session
     * (lazy-loaded chunks 404), so they stay.
     */
    private function clearCaches(): void
    {
        try {
            $this->kirby->cache('pages')->flush();
        } catch (Throwable) {
            // the pages cache may be disabled
        }

        $keep = [
            $this->kirby->root('media') . '/panel',
            $this->kirby->root('media') . '/plugins',
            Plugin::stateDir($this->kirby),
        ];

        foreach ([$this->kirby->root('cache'), $this->kirby->root('media')] as $root) {
            foreach (Dir::read($root, null, true) as $item) {
                // .gitkeep is not on Kirby's ignore list for Dir::read()
                if (in_array($item, $keep, true) === true || str_starts_with(basename($item), '.') === true) {
                    continue;
                }

                is_dir($item) === true ? Dir::remove($item) : F::remove($item);
            }
        }

        $this->log->write('caches cleared (site/cache, media)');
    }

    /**
     * Removes temp files left behind by a pull that was killed mid-write.
     */
    private function cleanupTempFiles(): void
    {
        foreach (Plugin::rootDirs($this->kirby) as $dir) {
            if (is_dir($dir) === false) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (
                    str_starts_with($file->getFilename(), Paths::TEMP_PREFIX) === true &&
                    $file->getMTime() < time() - 3600
                ) {
                    @unlink($file->getPathname());
                }
            }
        }
    }

    /**
     * One pull at a time per installation.
     *
     * @return resource
     */
    private function lock()
    {
        $file   = sys_get_temp_dir() . '/bb-content-pull-' . sha1($this->kirby->root('content')) . '.lock';
        $handle = fopen($file, 'c');

        if ($handle === false || flock($handle, LOCK_EX | LOCK_NB) === false) {
            throw new RuntimeException('Another content pull is running');
        }

        return $handle;
    }

    private function formatSummary(array $summary): string
    {
        return sprintf(
            'new=%d changed=%d deleted=%d skipped=%d unchanged=%d bytes=%d',
            $summary['new'],
            $summary['changed'],
            $summary['deleted'],
            $summary['skipped'],
            $summary['unchanged'],
            $summary['bytes']
        );
    }
}
