# bb/content-pull

Kirby-Plugin, mit dem eine Entwicklungs- oder Staging-Umgebung den `content/`-Ordner einer Quellumgebung (meist Production) **granular per CLI** übernimmt: nur Texte, nur Medien, nur eine Seite (mit oder ohne Unterseiten), nur bestimmte Sprachen, nur fehlende Dateien.

- Vergleich über ein Manifest mit SHA-1-Hashes – unveränderte Dateien werden nicht übertragen.
- Idempotent: Downloads landen in einer Temp-Datei, werden per Hash geprüft und erst dann umbenannt. Ein abgebrochener Pull hinterlässt keine halben Dateien; erneut ausführen genügt.
- `--json` für maschinenlesbare Ausgabe – Claude, Skripte und CI können direkt damit arbeiten.
- Kirby 4 und 5, PHP ≥ 8.2, keine Abhängigkeiten außer Kirby (HTTP über `Kirby\Http\Remote`).

Idee nach [zephir/kirby-contentsync](https://github.com/zephir-digital/kirby-contentsync), eigene Implementierung.

## Inhalt

- [Funktionsweise](#funktionsweise)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [CLI](#cli)
- [Quell-Endpunkte](#quell-endpunkte)
- [Sicherheit](#sicherheit)
- [Entwicklung](#entwicklung)

## Funktionsweise

```
Ziel (dev/staging)                          Quelle (production)
kirby content:pull ── GET /bb-content-pull/manifest ──▶  Liste: Pfad, sha1, Größe, mtime
   │  lokales Manifest + Diff                              (bereits gefiltert)
   ├─ pro Datei: GET /bb-content-pull/file?path=…  ──▶   einzelne Datei
   │  oder:      GET /bb-content-pull/archive?…    ──▶   ZIP des gefilterten Sets (--archive)
   ├─ Temp-Datei → sha1 prüfen → rename
   ├─ optional: lokale Dateien löschen, die es in der Quelle nicht gibt (--delete)
   └─ site/cache und media/ leeren, Log nach site/logs/content-pull.log
```

Pfade im Manifest beginnen immer mit ihrem Root: `content/1_about/default.de.txt`, `accounts/abc123/user.txt`.

**Immer ausgeschlossen:** `.lock`, `.DS_Store`, `.gitkeep` sowie Temp-Dateien des Plugins (`.bbpull-*`). Kirby-5-Änderungsstände (`_changes/`) nur mit `--include-changes`.

## Installation

Auf **Quelle und Ziel** installieren – dasselbe Plugin übernimmt je nach Konfiguration die eine oder andere Rolle.

### Composer (empfohlen)

```bash
composer config repositories.bb-content-pull vcs git@github.com:bold-and-bright/bb-content-pull.git
composer require bb/content-pull
```

Das Paket ist vom Typ `kirby-plugin` und landet über den `getkirby/composer-installer` in `site/plugins/bb-content-pull`.

### Manuell

Repository nach `site/plugins/bb-content-pull` kopieren oder als Git-Submodul einbinden. Ohne Composer registriert `index.php` einen eigenen Autoloader.

### Kirby CLI

Der Pull läuft über die [Kirby CLI](https://github.com/getkirby/cli). Entweder global (`composer global require getkirby/cli`) oder im Projekt:

```bash
composer require --dev getkirby/cli
```

In DDEV-Projekten dann im Container: `ddev exec vendor/bin/kirby content:pull …`.

## Konfiguration

Alle Optionen liegen unter `bb.contentpull.*`. **Als flache Punkt-Schlüssel setzen** – ein verschachteltes `'bb' => [...]`-Array überschreibt die Optionen anderer `bb.*`-Plugins.

| Option | Seite | Default | Beschreibung |
| --- | --- | --- | --- |
| `bb.contentpull.serve` | Quelle | `false` | Aktiviert die Quell-Endpunkte. Ohne `true` antworten sie mit 404. |
| `bb.contentpull.token` | beide | – | Gemeinsames Secret. Erzeugen mit `openssl rand -hex 32`. |
| `bb.contentpull.accounts` | Quelle | `false` | Erlaubt, `site/accounts` auszuliefern (für `--accounts`). |
| `bb.contentpull.source` | Ziel | – | Basis-URL der Quelle, z. B. `https://www.example.com`. |
| `bb.contentpull.timeout` | Ziel | `120` | HTTP-Timeout pro Request in Sekunden. |
| `bb.contentpull.verify` | Ziel | `true` | TLS-Zertifikat der Quelle prüfen. Nur für lokale Quellen mit Self-Signed-Zertifikat auf `false`. |

Den Token nicht ins Repository committen – aus `.env` oder einer nicht versionierten Datei lesen.

### `site/config/config.php` (gemeinsam)

```php
<?php

return [
    // Token aus der Umgebung, nicht im Repo
    'bb.contentpull.token' => $_ENV['CONTENT_PULL_TOKEN'] ?? null,
];
```

### `site/config/config.production.php` (Quelle)

Kirby lädt `config.{host}.php` passend zum Hostnamen, z. B. `config.www.example.com.php`.

```php
<?php

return [
    'bb.contentpull.serve' => true,
    // optional: Benutzerkonten mit ausliefern (für --accounts)
    // 'bb.contentpull.accounts' => true,
];
```

### `site/config/config.staging.php` (Ziel)

```php
<?php

return [
    'bb.contentpull.source' => 'https://www.example.com',
];
```

### Lokale Entwicklung (Ziel, z. B. `config.example.ddev.site.php`)

```php
<?php

return [
    'bb.contentpull.source' => 'https://www.example.com',
    // oder Staging als Quelle – dort dann zusätzlich serve => true:
    // 'bb.contentpull.source' => 'https://staging.example.com',
];
```

Eine Umgebung kann Quelle **und** Ziel sein (Staging holt von Production und liefert an Dev). Ein Pull auf sich selbst wird abgelehnt.

## CLI

```
kirby content:pull [--scope=texts|media|all] [--page=<id>] [--recursive]
                   [--lang=de,en] [--missing-only] [--max-size=20M]
                   [--delete] [--dry-run] [--accounts] [--include-changes]
                   [--archive] [--json]
```

| Option | Beschreibung |
| --- | --- |
| `--scope` | `texts` = alle `*.txt` inkl. Datei-Metadaten (`bild.jpg.de.txt`), `media` = alle anderen Dateien, `all` (Default). |
| `--page` | Nur dieser Seitenordner, als Kirby-Page-ID (`ueber-uns/team`). Sortiernummern und `_drafts` werden ignoriert, Entwürfe sind also adressierbar. `site` = nur `site.*.txt` und Site-Dateien. |
| `--recursive` | Mit `--page`: inkl. aller Unterseiten. `--page=site --recursive` = alles. |
| `--lang` | Nur Textdateien dieser Sprachen. Medien sind sprachunabhängig und bleiben drin. |
| `--missing-only` | Nur Dateien holen, die lokal fehlen; vorhandene werden nicht verglichen. |
| `--max-size` | Medien oberhalb dieser Größe überspringen (`500K`, `20M`, `1G`). Textdateien nie. |
| `--delete` | Lokale Dateien **im gewählten Scope** löschen, die es in der Quelle nicht gibt. Leere Seitenordner werden entfernt. Default aus. |
| `--dry-run` | Nur die Diff-Liste ausgeben: neu / geändert / gelöscht / übersprungen. |
| `--accounts` | Zusätzlich `site/accounts` syncen (Quelle braucht `bb.contentpull.accounts`). Accounts werden immer komplett übernommen, Scope/Page/Lang gelten nur für Content. |
| `--include-changes` | Kirby-5-`_changes`-Ordner (ungespeicherte Panel-Änderungen) mitnehmen. |
| `--archive` | Das gefilterte Set als ein ZIP holen statt Datei für Datei – sinnvoll für viele Medien. Übernommen werden trotzdem nur die Dateien aus dem Diff. Braucht `ext-zip` auf beiden Seiten. |
| `--json` | Ein JSON-Dokument auf stdout statt Fortschrittsausgabe. |

Exit-Code `0` bei Erfolg, `1` bei Fehlern (Konfiguration, Quelle nicht erreichbar, einzelne Dateien fehlgeschlagen).

### Beispiele

```bash
# Was würde sich ändern?
kirby content:pull --dry-run

# Alles holen
kirby content:pull

# Nur Texte – schnell, ohne Medien
kirby content:pull --scope=texts

# Nur Medien, die lokal fehlen, ohne große Videos
kirby content:pull --scope=media --missing-only --max-size=20M

# Eine Seite mit allen Unterseiten
kirby content:pull --page=leistungen --recursive

# Nur die englischen Texte einer Seite
kirby content:pull --scope=texts --page=ueber-uns --lang=en

# Site-Einstellungen (site.*.txt, Logo etc.)
kirby content:pull --page=site

# Exakter Spiegel inkl. Löschen – erst ansehen, dann ausführen
kirby content:pull --delete --dry-run
kirby content:pull --delete

# Erstbefüllung mit vielen Medien in einem ZIP
kirby content:pull --scope=media --archive

# Inklusive Benutzerkonten
kirby content:pull --accounts

# In DDEV
ddev exec vendor/bin/kirby content:pull --scope=texts
```

### JSON-Ausgabe (für Claude, Skripte, CI)

```bash
kirby content:pull --page=kontakt --dry-run --json
```

```json
{
    "ok": true,
    "error": null,
    "source": "https://www.example.com",
    "options": { "scope": "all", "page": "kontakt", "recursive": false, "dryRun": true, "…": "…" },
    "diff": {
        "new":     [{ "path": "content/4_kontakt/default.de.txt", "sha1": "839f…", "size": 30, "mtime": 1792077152 }],
        "changed": [],
        "deleted": [],
        "skipped": [{ "path": "content/4_kontakt/map.pdf", "size": 9000000, "reason": "size", "…": "…" }],
        "unchanged": 0
    },
    "summary": { "new": 1, "changed": 0, "deleted": 0, "skipped": 1, "unchanged": 0, "bytes": 30 },
    "result": null
}
```

Ohne `--dry-run` enthält `result` die Listen `written`, `deleted`, `failed` (`[{path, error}]`) und `duration`. Bei einem Abbruch: `{"ok": false, "error": "…"}` und Exit-Code 1.

`skipped.reason` ist `size` (über `--max-size`) oder `invalid path` (von der Quelle gelieferter Pfad, der die Validierung nicht besteht – wird nie geschrieben).

### Nach dem Pull

- `site/cache` und `media/` werden geleert (Kirby-Cache-API bzw. `Kirby\Filesystem\Dir`). `media/panel` und `media/plugins` bleiben, sonst bricht ein gerade geöffnetes Panel ab.
- Jeder Lauf wird in `site/logs/content-pull.log` protokolliert: Start mit Optionen, jede geschriebene / gelöschte / fehlgeschlagene Datei, Abschluss.
- Es läuft immer nur ein Pull pro Installation gleichzeitig (Lock-Datei im System-Temp).

## Quell-Endpunkte

Nur aktiv mit `bb.contentpull.serve => true`. Authentifizierung per `Authorization: Bearer <token>`; als Fallback für Hoster, die den Authorization-Header verwerfen, auch `X-Content-Pull-Token: <token>`. Ohne gültigen Token: `401`.

| Endpunkt | Beschreibung |
| --- | --- |
| `GET /bb-content-pull/manifest` | JSON `{generated, languages, files: [{path, sha1, size, mtime}]}`. Akzeptiert `scope`, `page`, `recursive`, `lang`, `accounts`, `include-changes`; `hash=0` liefert eine billige Liste ohne Hashes. |
| `GET /bb-content-pull/file?path=…` | Einzelne Datei, immer als `application/octet-stream`-Download, gestreamt. |
| `GET /bb-content-pull/archive?…` | ZIP des gefilterten Sets (gleiche Parameter wie `manifest`, zusätzlich `max-size`). Medien unkomprimiert gespeichert. |

```bash
curl -H "Authorization: Bearer $TOKEN" "https://www.example.com/bb-content-pull/manifest?scope=texts&page=ueber-uns"
```

## Sicherheit

- Alle Pfade – in Anfragen an die Quelle **und** im Manifest, das das Ziel empfängt – laufen durch `Paths::normalize()`: keine absoluten Pfade, kein `..`/`.`, keine Backslashes, Steuerzeichen oder Laufwerksbuchstaben, nur unterhalb von `content/` (bzw. `accounts/`, wenn erlaubt).
- `Paths::resolve()` prüft zusätzlich per `realpath`, dass das Ziel innerhalb des Roots liegt; Symlinks nach außen werden abgelehnt. Die Quelle liefert keine Symlinks aus.
- ZIP-Archive werden nie blind entpackt, sondern Eintrag für Eintrag per Name gelesen und gegen den Hash aus dem Manifest geprüft.
- Token-Vergleich mit `hash_equals`. Ausgelieferte Dateien sind immer Downloads (`nosniff`), nichts wird gerendert.
- `site/accounts` enthält Passwort-Hashes: `bb.contentpull.accounts` nur aktivieren, wenn das wirklich gebraucht wird.

## Entwicklung

```bash
composer install
composer test
```

Die Unit-Tests decken Pfad-Validierung, Manifest-Filterung, Diff und Optionen ab und kommen ohne Kirby aus.

| Klasse | Aufgabe |
| --- | --- |
| `Paths` | Pfad-Validierung, Page-ID- und Sprach-Erkennung, Ausschlüsse |
| `Options` | Optionen aus CLI-Argumenten bzw. Query |
| `Filter` | Welche Pfade gehören zu einem Pull (Scope, Page, Sprachen) |
| `Manifest` | Dateien scannen bzw. entferntes Manifest validieren |
| `Diff` | Neu / geändert / gelöscht / übersprungen |
| `Client` | HTTP zur Quelle über `Kirby\Http\Remote` |
| `Puller` | Der Pull: Plan, Downloads, Löschen, Caches, Log |
| `Server` | Quell-Endpunkte |
| `Command` | `kirby content:pull` |
