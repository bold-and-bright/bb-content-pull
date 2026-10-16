# bb/content-pull

Kirby plugin that lets a development or staging environment take over the `content/` folder of a source environment (usually production) **granularly via the CLI**: only texts, only media, only one page (with or without subpages), only certain languages, only missing files.

- Compares via a manifest with SHA-1 hashes – unchanged files are not transferred.
- Idempotent: downloads go to a temp file, are verified against their hash and only then renamed. An aborted pull leaves no half-written files; just run it again.
- `--json` for machine-readable output – scripts, CI and other tools can work with it directly.
- Optional: plugin updates straight from the Panel or via the CLI, from the GitHub releases.
- Kirby 4 and 5, PHP ≥ 8.2, no dependencies besides Kirby (HTTP via `Kirby\Http\Remote`).

Idea based on [zephir/kirby-contentsync](https://github.com/zephir-digital/kirby-contentsync), own implementation.

## Contents

- [How it works](#how-it-works)
- [Installation](#installation)
- [Configuration](#configuration)
- [CLI](#cli)
- [Caches](#caches)
- [Plugin updates](#plugin-updates)
- [Source endpoints](#source-endpoints)
- [Security](#security)
- [Development](#development)

## How it works

```
Target (dev/staging)                         Source (production)
kirby content:pull ── GET /bb-content-pull/manifest ──▶  list: path, sha1, size, mtime
   │  local manifest + diff                                (already filtered)
   ├─ per file: GET /bb-content-pull/file?path=…  ──▶    single file
   │  or:       GET /bb-content-pull/archive?…    ──▶    ZIP of the filtered set (--archive)
   ├─ temp file → verify sha1 → rename
   ├─ optional: delete local files that do not exist on the source (--delete)
   └─ clear site/cache and media/, log to site/logs/content-pull.log
```

Paths in the manifest always start with their root: `content/1_about/default.en.txt`, `accounts/abc123/user.txt`.

**Always excluded:** `.lock`, `.DS_Store`, `.gitkeep`, `.logins` (Kirby's login throttling in `site/accounts`) and the plugin's temp files (`.bbpull-*`). Kirby 5 change versions (`_changes/`) only with `--include-changes`.

## Installation

Install on **both source and target** – the same plugin takes on one role or the other depending on its configuration.

### Composer (recommended)

```bash
composer config repositories.bb-content-pull vcs https://github.com/bold-and-bright/bb-content-pull.git
composer require bb/content-pull
```

The package is of type `kirby-plugin` and ends up in `site/plugins/bb-content-pull` via `getkirby/composer-installer`.

### Manual

Download `bb-content-pull.zip` from the [latest release](https://github.com/bold-and-bright/bb-content-pull/releases/latest) and unpack it to `site/plugins/bb-content-pull`, or add the repository as a Git submodule. Without Composer, `index.php` registers its own autoloader.

### Kirby CLI

Pulling runs through the [Kirby CLI](https://github.com/getkirby/cli). Install it either globally (`composer global require getkirby/cli`) or in the project:

```bash
composer require --dev getkirby/cli
```

In DDEV projects, run it inside the container: `ddev exec vendor/bin/kirby content:pull …`.

## Configuration

All options live under `bb.contentpull.*`. **Set them as flat dot keys** – a nested `'bb' => [...]` array overrides the options of other `bb.*` plugins.

| Option | Side | Default | Description |
| --- | --- | --- | --- |
| `bb.contentpull.serve` | source | `false` | Enables the source endpoints. Without `true` they answer with 404. |
| `bb.contentpull.token` | both | – | Shared secret. Generate with `openssl rand -hex 32`. Optional on the target when pulling with a Kirby login (`--user`). |
| `bb.contentpull.roles` | source | `['admin']` | Roles allowed to log in with their Kirby credentials to pull. Only admins get accounts. |
| `bb.contentpull.sessionTtl` | source | `7200` | Lifetime of the session token after a Kirby login, in seconds. |
| `bb.contentpull.accounts` | source | `false` | Allows serving `site/accounts` (for `--accounts`). |
| `bb.contentpull.source` | target | – | Base URL of the source, e.g. `https://www.example.com`. |
| `bb.contentpull.timeout` | target | `120` | HTTP timeout per request in seconds. |
| `bb.contentpull.verify` | target | `true` | Verify the source's TLS certificate. Set to `false` only for local sources with self-signed certificates. |
| `bb.contentpull.hashCache` | both | `true` | Reuse hashes of unchanged files (same size + mtime), see [Caches](#caches). |
| `bb.contentpull.cache` | target | – | Directory of a file cache shared by several target environments, see [Caches](#caches). |
| `bb.contentpull.updates` | both | `false` | Offer updates in the Panel, see [Plugin updates](#plugin-updates). |
| `bb.contentpull.updateRepository` | both | `bold-and-bright/bb-content-pull` | GitHub repository of the releases. |
| `bb.contentpull.updateApi` | both | `https://api.github.com` | API base URL (e.g. GitHub Enterprise). |

Do not commit the token to the repository – read it from `.env` or another unversioned file.

### `site/config/config.php` (shared)

```php
<?php

return [
    // token from the environment, not from the repo
    'bb.contentpull.token' => $_ENV['CONTENT_PULL_TOKEN'] ?? null,
];
```

### `site/config/config.www.example.com.php` (source)

Kirby loads `config.{host}.php` matching the host name.

```php
<?php

return [
    'bb.contentpull.serve' => true,
    // optional: also serve user accounts (for --accounts)
    // 'bb.contentpull.accounts' => true,
];
```

### `site/config/config.staging.example.com.php` (target)

```php
<?php

return [
    'bb.contentpull.source' => 'https://www.example.com',
];
```

### Local development (target, e.g. `config.example.ddev.site.php`)

```php
<?php

return [
    'bb.contentpull.source' => 'https://www.example.com',
    // or staging as the source – it then also needs serve => true:
    // 'bb.contentpull.source' => 'https://staging.example.com',
];
```

An environment can be source **and** target at the same time (staging pulls from production and serves dev). Pulling from itself is refused.

## CLI

```
kirby content:pull [--scope=texts|media|all|accounts] [--page=<id>] [--recursive]
                   [--lang=de,en] [--missing-only] [--max-size=20M]
                   [--delete] [--dry-run] [--accounts] [--include-changes]
                   [--archive] [--json]
                   [--user=<email> [--password=<pw>] | --login]
```

| Option | Description |
| --- | --- |
| `--scope` | `texts` = all `*.txt` incl. file metadata (`image.jpg.en.txt`), `media` = all other files, `all` (default), `accounts` = only `site/accounts`, no content. |
| `--page` | Only this page folder, as a Kirby page ID (`about/team`). Sorting numbers and `_drafts` are ignored, so drafts can be addressed too. `site` = only `site.*.txt` and site files. |
| `--recursive` | With `--page`: including all subpages. `--page=site --recursive` = everything. |
| `--lang` | Only text files of these languages. Media is language-independent and stays included. |
| `--missing-only` | Only fetch files that are missing locally; existing ones are not compared. |
| `--max-size` | Skip media above this size (`500K`, `20M`, `1G`). Never applies to text files. |
| `--delete` | Delete local files **in the selected scope** that do not exist on the source. Empty page folders are removed. Off by default. |
| `--dry-run` | Only print the diff: new / changed / deleted / skipped. |
| `--accounts` | Also sync `site/accounts` in addition to the content. Accounts are always taken over completely; scope/page/lang only apply to content. Accounts only: `--scope=accounts`. Both need `bb.contentpull.accounts` on the source. |
| `--include-changes` | Include Kirby 5 `_changes` folders (unsaved Panel changes). |
| `--archive` | Fetch the filtered set as one ZIP instead of file by file – useful for many media files. Still only the files from the diff are applied. Needs `ext-zip` on both sides. |
| `--json` | One JSON document on stdout instead of progress output. |
| `--user` | Log in to the source with this Kirby user (email) instead of the token. Without `--password`, the password is prompted for (hidden input). |
| `--password` | Password for `--user`. Better leave it out and let it be prompted – arguments end up in the shell history and are visible in the process list. |
| `--login` | Prompt for email and password. |

Exit code `0` on success, `1` on errors (configuration, source unreachable, individual files failed).

### Examples

```bash
# What would change?
kirby content:pull --dry-run

# Fetch everything
kirby content:pull

# Texts only – fast, without media
kirby content:pull --scope=texts

# Only media missing locally, without large videos
kirby content:pull --scope=media --missing-only --max-size=20M

# One page with all subpages
kirby content:pull --page=services --recursive

# Only the English texts of one page
kirby content:pull --scope=texts --page=about --lang=en

# Site settings (site.*.txt, logo etc.)
kirby content:pull --page=site

# Exact mirror incl. deletions – review first, then run
kirby content:pull --delete --dry-run
kirby content:pull --delete

# Initial fill with many media files in one ZIP
kirby content:pull --scope=media --archive

# Including user accounts
kirby content:pull --accounts

# User accounts only (e.g. for the Panel login on staging)
kirby content:pull --scope=accounts

# In DDEV
ddev exec vendor/bin/kirby content:pull --scope=texts
```

### With Kirby credentials instead of the token

Developers can pull with their own Kirby account on the source – the target then does not need to know the token at all:

```bash
# password is prompted for (hidden)
kirby content:pull --user=dev@example.com --dry-run

# prompt for email and password
kirby content:pull --login

# non-interactive (e.g. scripts) – the password ends up in the shell history
kirby content:pull --user=dev@example.com --password='…' --json
```

How it works: the CLI logs in once via `POST /bb-content-pull/login` (HTTP Basic, fallback header `X-Content-Pull-Auth`). The source checks the credentials with Kirby's `Auth::validatePassword()` – including the lockout after too many failed attempts and the delay on a wrong password – and returns a signed session token (2 hours by default) used for all further requests. The token is signed with the user's password hash: when the password changes, it becomes invalid immediately. Only roles from `bb.contentpull.roles` are allowed (default: admins); only admins get accounts. A second factor (login code) is not requested. Prompts go to stderr, so `--json` on stdout stays clean. The log records the user: `[cli:dev@example.com]`.

### JSON output (for scripts and CI)

```bash
kirby content:pull --page=contact --dry-run --json
```

```json
{
    "ok": true,
    "error": null,
    "source": "https://www.example.com",
    "options": { "scope": "all", "page": "contact", "recursive": false, "dryRun": true, "…": "…" },
    "diff": {
        "new":     [{ "path": "content/4_contact/default.en.txt", "sha1": "839f…", "size": 30, "mtime": 1792077152 }],
        "changed": [],
        "deleted": [],
        "skipped": [{ "path": "content/4_contact/map.pdf", "size": 9000000, "reason": "size", "…": "…" }],
        "unchanged": 0
    },
    "summary": { "new": 1, "changed": 0, "deleted": 0, "skipped": 1, "unchanged": 0, "bytes": 30 },
    "result": null
}
```

Without `--dry-run`, `result` contains the lists `written`, `deleted`, `failed` (`[{path, error}]`), `cached` (number of files taken from the shared cache) and `duration`. On abort: `{"ok": false, "error": "…"}` and exit code 1.

`skipped.reason` is `size` (above `--max-size`) or `invalid path` (a path delivered by the source that fails validation – never written).

### After the pull

- `site/cache` and `media/` are cleared (Kirby cache API and `Kirby\Filesystem\Dir`). `media/panel`, `media/plugins` and dotfiles (`.gitkeep`, `.htaccess`) are kept – otherwise an open Panel would break and versioned placeholders would disappear.
- Every run is logged to `site/logs/content-pull.log`: start with options, every written / deleted / failed file, completion.
- Only one pull runs per installation at a time (lock file in the system temp directory).

## Caches

### Hash cache (source and target)

Without a cache, the source reads every file in scope completely for each manifest to compute its SHA-1 – with a large media library that is hundreds of MB per pull. The hash cache remembers size, mtime and hash per path in `site/cache/bb-content-pull/hashes.json` (or the `cache` root) and only re-hashes files that changed. The same applies to the local comparison on the target. The folder is kept when the cache is cleared after a pull.

On by default; turn it off with `'bb.contentpull.hashCache' => false`.

### Shared file cache (target)

If several target environments run on the same server – staging and PR previews, for example – each would otherwise fetch all media from the source itself. With

```php
'bb.contentpull.cache' => '/var/cache/content-pull',
```

every environment additionally stores downloaded files under their SHA-1 (`/var/cache/content-pull/ab/abcdef…`). Before each download, the cache is checked first; hits are copied and verified against the hash from the manifest, damaged entries are discarded and downloaded again. This way the source transfers each file only once, no matter how many environments need it.

The directory must be the same for all environments (e.g. a bind mount from the host into each container) and writable for the PHP user. No cleanup is needed as long as there is enough space; otherwise the folder can be emptied at any time.

## Plugin updates

Two ways, depending on the project:

**With Git-based deployment (recommended):** install the plugin via Composer and receive updates as pull requests (e.g. via a Composer update bot, Dependabot or Renovate). An update in the Panel would be overwritten by the next deployment.

**Without Git-based deployment (optional):** update directly from the Panel or via the CLI, from the GitHub releases of this repository.

```php
'bb.contentpull.updates' => true,
```

- **Panel:** no menu entry of its own – under *System → Plugins*, the version column of `bb/content-pull` shows the update status (like Kirby's own update notices). Its dropdown leads to the *System › Content Pull Update* view with the release notes and buttons to update or restore the previous version. Without the option, the system view stays unchanged. (The view's texts are currently German.)
- **CLI** (independent of the option):
  ```bash
  kirby content-pull:update --check        # installed and latest version
  kirby content-pull:update                # update to the latest version
  kirby content-pull:update --version=1.4.0
  kirby content-pull:update --rollback     # back to the previous version
  ```

How it works: determine the release via the GitHub API (cached for 6 hours), download `bb-content-pull.zip` and `bb-content-pull.zip.sha256`, compare the checksum, unpack only entries below `bb-content-pull/` (no `..`, no symlinks), then swap the folders via `rename`. The previous version stays as `site/plugins/.bb-content-pull.backup` – Kirby does not load folders with a leading dot. Afterwards the opcache is reset; every update is logged.

Requirements: public repository (the API is queried without a token), PHP may write to `site/plugins`, `ext-zip`. The Panel shows a hint if the project is a Git checkout or the plugin was installed via Composer.

### Creating a release

1. Increase `version` in `composer.json` and commit.
2. Tag and push: `git tag v1.4.0 && git push origin v1.4.0`.
3. The workflow `.github/workflows/release.yml` runs the tests, checks the tag against `composer.json`, builds the ZIP via `git archive` (without tests and CI files, see `.gitattributes`) plus its SHA-256 and attaches both to the GitHub release.

## Source endpoints

Only active with `bb.contentpull.serve => true`. Authentication via `Authorization: Bearer <token>` – the shared token or a session token from `/login`; as a fallback for hosts that drop the Authorization header, `X-Content-Pull-Token: <token>` works too. Without a valid token: `401`.

| Endpoint | Description |
| --- | --- |
| `GET /bb-content-pull/manifest` | JSON `{generated, languages, files: [{path, sha1, size, mtime}]}`. Accepts `scope`, `page`, `recursive`, `lang`, `accounts`, `include-changes`; `hash=0` returns a cheap list without hashes. |
| `GET /bb-content-pull/file?path=…` | Single file, always as an `application/octet-stream` download, streamed. |
| `GET /bb-content-pull/archive?…` | ZIP of the filtered set (same parameters as `manifest`, plus `max-size`). Media is stored uncompressed. |
| `POST /bb-content-pull/login` | Kirby credentials via HTTP Basic (or `X-Content-Pull-Auth: base64(email:password)`) → `{token, expires, user, role}`. The token then works like the shared token. |

```bash
curl -H "Authorization: Bearer $TOKEN" "https://www.example.com/bb-content-pull/manifest?scope=texts&page=about"
```

## Security

- All paths – in requests to the source **and** in the manifest the target receives – go through `Paths::normalize()`: no absolute paths, no `..`/`.`, no backslashes, control characters or drive letters, only below `content/` (or `accounts/`, if allowed).
- `Paths::resolve()` additionally checks via `realpath` that the target lies inside the root; symlinks pointing outside are rejected. The source never serves symlinks.
- ZIP archives are never extracted blindly but read entry by entry by name and verified against the hash from the manifest.
- Token comparison with `hash_equals`. Served files are always downloads (`nosniff`); nothing is rendered.
- `site/accounts` contains password hashes: only enable `bb.contentpull.accounts` when really needed, and do not make the target (staging, previews) publicly accessible. With the shared file cache, the account files are stored there as well.
- Without `bb.contentpull.accounts`, the source rejects every request involving accounts (`403`).

## Development

```bash
composer install
composer test
```

The unit tests cover path validation, manifest filtering, diff, options, caches and session tokens, and run without Kirby.

| Class | Purpose |
| --- | --- |
| `Paths` | Path validation, page ID and language detection, exclusions |
| `Options` | Options from CLI arguments or the query |
| `Filter` | Which paths belong to a pull (scope, page, languages) |
| `Manifest` | Scan files and validate a remote manifest |
| `Diff` | New / changed / deleted / skipped |
| `Client` | HTTP to the source via `Kirby\Http\Remote` |
| `Puller` | The pull: plan, downloads, deletions, caches, log |
| `HashCache` | Reuse hashes of unchanged files |
| `ObjectCache` | Shared, content-addressed file cache |
| `Server` | Source endpoints incl. Kirby login |
| `SessionToken` | Signed, short-lived token after a Kirby login |
| `Updater`, `UpdateApi`, `UpdateCommand` | Optional self-update from GitHub releases (Panel + CLI) |
| `Command` | `kirby content:pull` |

## License

MIT – see [LICENSE](LICENSE).
