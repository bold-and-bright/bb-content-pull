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
 * Optional self-update from GitHub releases (`bb.contentpull.updates`).
 *
 * A release carries two assets, built by .github/workflows/release.yml:
 *   bb-content-pull.zip         the plugin folder "bb-content-pull/"
 *   bb-content-pull.zip.sha256  its SHA-256
 *
 * The new version is unpacked next to the plugin into a dot folder (Kirby
 * skips those when loading plugins), then swapped in with two renames. The
 * previous version stays as ".bb-content-pull.backup" for a rollback.
 */
final class Updater
{
    public const ASSET = 'bb-content-pull.zip';

    public function __construct(
        private readonly App $kirby,
        private readonly string $repository,
        private readonly string $pluginDir,
        private readonly string $api = 'https://api.github.com',
    ) {
    }

    public static function fromConfig(App $kirby): self
    {
        return new self(
            $kirby,
            (string) Plugin::option('updateRepository', 'bold-and-bright/bb-content-pull'),
            dirname(__DIR__),
            rtrim((string) Plugin::option('updateApi', 'https://api.github.com'), '/'),
        );
    }

    public static function enabled(): bool
    {
        return Plugin::option('updates', false) === true;
    }

    public function currentVersion(): string
    {
        $composer = json_decode((string) F::read($this->pluginDir . '/composer.json'), true);

        return is_string($composer['version'] ?? null) === true ? $composer['version'] : '0.0.0';
    }

    /**
     * Latest release, cached for some hours to stay clear of the GitHub API
     * rate limit (60 requests/hour without token).
     *
     * @return array{version: string, tag: string, notes: string, url: string, zip: string, sha256: string, published: string}
     */
    public function latest(bool $refresh = false): array
    {
        $cache = Plugin::stateDir($this->kirby) . '/update.json';

        if ($refresh === false && is_file($cache) === true && filemtime($cache) > time() - 6 * 3600) {
            $data = json_decode((string) F::read($cache), true);

            if (is_array($data) === true && isset($data['version']) === true) {
                return $data;
            }
        }

        $release = $this->release('releases/latest');
        F::write($cache, (string) json_encode($release));

        return $release;
    }

    /**
     * @return array{version: string, tag: string, notes: string, url: string, zip: string, sha256: string, published: string}
     */
    public function release(string $endpoint): array
    {
        $data = json_decode($this->get($this->api . '/repos/' . $this->repository . '/' . $endpoint), true);

        if (is_array($data) === false || is_string($data['tag_name'] ?? null) === false) {
            throw new RuntimeException('GitHub returned no release for ' . $this->repository);
        }

        $assets = [];
        foreach ($data['assets'] ?? [] as $asset) {
            $assets[$asset['name']] = $asset['browser_download_url'];
        }

        if (isset($assets[self::ASSET], $assets[self::ASSET . '.sha256']) === false) {
            throw new RuntimeException('Release ' . $data['tag_name'] . ' has no ' . self::ASSET . ' (+ .sha256)');
        }

        return [
            'version'   => ltrim($data['tag_name'], 'v'),
            'tag'       => $data['tag_name'],
            'notes'     => (string) ($data['body'] ?? ''),
            'url'       => (string) ($data['html_url'] ?? ''),
            'zip'       => $assets[self::ASSET],
            'sha256'    => $assets[self::ASSET . '.sha256'],
            'published' => (string) ($data['published_at'] ?? ''),
        ];
    }

    /**
     * @return array{current: string, latest: ?array, available: bool, backup: ?string, writable: bool, warnings: array<int, string>, error: ?string}
     */
    public function status(bool $refresh = false): array
    {
        $latest = null;
        $error  = null;

        try {
            $latest = $this->latest($refresh);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'current'   => $this->currentVersion(),
            'latest'    => $latest,
            'available' => $latest !== null && version_compare($latest['version'], $this->currentVersion(), '>'),
            'backup'    => $this->backupVersion(),
            'writable'  => $this->writable(),
            'warnings'  => $this->warnings(),
            'error'     => $error,
        ];
    }

    /**
     * Installs the latest release, or a given version ("1.4.0").
     */
    public function install(?string $version = null): string
    {
        if ($this->writable() === false) {
            throw new RuntimeException('The plugin folder is not writable for PHP: ' . $this->pluginDir);
        }

        $release = $version === null
            ? $this->latest(refresh: true)
            : $this->release('releases/tags/v' . ltrim($version, 'v'));

        $parent  = dirname($this->pluginDir);
        $suffix  = bin2hex(random_bytes(4));
        $zipFile = $parent . '/.bb-content-pull.download-' . $suffix . '.zip';
        $staging = $parent . '/.bb-content-pull.new-' . $suffix;

        try {
            F::write($zipFile, $this->get($release['zip']));

            $expected = strtolower(substr(trim($this->get($release['sha256'])), 0, 64));

            if (hash_equals($expected, (string) hash_file('sha256', $zipFile)) === false) {
                throw new RuntimeException('Checksum mismatch – download incomplete or tampered with');
            }

            $this->extract($zipFile, $staging);
            $this->swap($staging . '/bb-content-pull');
        } finally {
            F::remove($zipFile);
            Dir::remove($staging);
        }

        $this->resetOpcache();

        return $release['version'];
    }

    /**
     * Swaps the backup back in (the current version becomes the backup).
     */
    public function rollback(): string
    {
        $backup = $this->backupDir();

        if (is_dir($backup) === false) {
            throw new RuntimeException('No backup to roll back to');
        }

        $parent = dirname($this->pluginDir);
        $temp   = $parent . '/.bb-content-pull.rollback-' . bin2hex(random_bytes(4));

        rename($backup, $temp);
        $this->swap($temp);
        $this->resetOpcache();

        return $this->currentVersion();
    }

    public function backupVersion(): ?string
    {
        $composer = $this->backupDir() . '/composer.json';

        if (is_file($composer) === false) {
            return null;
        }

        return json_decode((string) F::read($composer), true)['version'] ?? null;
    }

    private function backupDir(): string
    {
        return dirname($this->pluginDir) . '/.bb-content-pull.backup';
    }

    private function writable(): bool
    {
        return is_writable($this->pluginDir) === true && is_writable(dirname($this->pluginDir)) === true;
    }

    /**
     * Hints when an update from the panel does not fit the project setup.
     *
     * @return array<int, string>
     */
    private function warnings(): array
    {
        $warnings = [];
        $base     = $this->kirby->root('base') ?? dirname($this->kirby->root('index'));

        if (is_dir($base . '/.git') === true || is_dir(dirname($this->pluginDir, 3) . '/.git') === true) {
            $warnings[] = 'This project is a Git checkout – an update here is overwritten by the next deploy unless it is committed.';
        }

        if (is_file($base . '/composer.lock') === true && str_contains((string) F::read($base . '/composer.lock'), '"name": "bb/content-pull"') === true) {
            $warnings[] = 'The plugin is installed via Composer – prefer `composer update bb/content-pull`.';
        }

        return $warnings;
    }

    /**
     * Unpacks only entries below "bb-content-pull/" – no absolute paths, no
     * "..", no symlinks.
     */
    private function extract(string $zipFile, string $target): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Invalid release archive');
        }

        Dir::make($target);

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (str_ends_with($name, '/') === true) {
                    continue;
                }

                $segments = explode('/', $name);

                if (
                    $segments[0] !== 'bb-content-pull' ||
                    in_array('..', $segments, true) === true ||
                    in_array('', $segments, true) === true ||
                    str_contains($name, '\\') === true
                ) {
                    throw new RuntimeException('Unexpected entry in release archive: ' . $name);
                }

                // Symlinks are stored with the S_IFLNK bit in the external attributes
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Symlink in release archive: ' . $name);
                }

                F::write($target . '/' . $name, (string) $zip->getFromIndex($i));
            }
        } finally {
            $zip->close();
        }

        if (is_file($target . '/bb-content-pull/index.php') === false || is_file($target . '/bb-content-pull/composer.json') === false) {
            throw new RuntimeException('Release archive does not contain the plugin');
        }
    }

    /**
     * Current → backup, new → current. Restores the current version if the
     * second rename fails.
     */
    private function swap(string $new): void
    {
        $backup = $this->backupDir();

        if (is_dir($backup) === true) {
            Dir::remove($backup);
        }

        if (rename($this->pluginDir, $backup) === false) {
            throw new RuntimeException('Could not move the current version aside');
        }

        if (rename($new, $this->pluginDir) === false) {
            rename($backup, $this->pluginDir);
            throw new RuntimeException('Could not move the new version into place');
        }
    }

    private function resetOpcache(): void
    {
        if (function_exists('opcache_reset') === true) {
            @opcache_reset();
        }
    }

    private function get(string $url): string
    {
        $remote = Remote::get($url, [
            'headers' => [
                'Accept'     => str_starts_with($url, $this->api) ? 'application/vnd.github+json' : '*/*',
                // GitHub's API rejects requests without a user agent
                'User-Agent' => 'bb-content-pull-updater',
            ],
            'timeout' => 60,
        ]);

        $code    = $remote->code();
        $content = (string) $remote->content();
        unset($remote);
        gc_collect_cycles();

        if ($code !== 200) {
            throw new RuntimeException('GitHub answered with HTTP ' . $code . ' for ' . $url);
        }

        return $content;
    }
}
