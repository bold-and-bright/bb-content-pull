# bb/content-pull

Kirby-Plugin, mit dem eine Entwicklungs- oder Staging-Umgebung den `content/`-Ordner einer Quellumgebung (meist Production) **granular per CLI** übernimmt: nur Texte, nur Medien, nur eine Seite (mit oder ohne Unterseiten), nur bestimmte Sprachen, nur fehlende Dateien.

- Vergleich über ein Manifest mit SHA-1-Hashes – unveränderte Dateien werden nicht übertragen.
- Idempotent: Downloads landen in einer Temp-Datei, werden per Hash geprüft und erst dann umbenannt. Ein abgebrochener Pull hinterlässt keine halben Dateien; erneut ausführen genügt.
- `--json` für maschinenlesbare Ausgabe – Claude, Skripte und CI können direkt damit arbeiten.
- Optional: Plugin-Updates direkt aus dem Panel bzw. per CLI aus den GitHub-Releases.
- Kirby 4 und 5, PHP ≥ 8.2, keine Abhängigkeiten außer Kirby (HTTP über `Kirby\Http\Remote`).

Idee nach [zephir/kirby-contentsync](https://github.com/zephir-digital/kirby-contentsync), eigene Implementierung.

## Inhalt

- [Funktionsweise](#funktionsweise)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [CLI](#cli)
- [Caches](#caches)
- [Plugin-Updates](#plugin-updates)
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

**Immer ausgeschlossen:** `.lock`, `.DS_Store`, `.gitkeep`, `.logins` (Kirbys Login-Drosselung in `site/accounts`) sowie Temp-Dateien des Plugins (`.bbpull-*`). Kirby-5-Änderungsstände (`_changes/`) nur mit `--include-changes`.

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
| `bb.contentpull.token` | beide | – | Gemeinsames Secret. Erzeugen mit `openssl rand -hex 32`. Auf dem Ziel optional, wenn per Kirby-Login gezogen wird (`--user`). |
| `bb.contentpull.roles` | Quelle | `['admin']` | Rollen, die sich mit ihren Kirby-Zugangsdaten zum Ziehen anmelden dürfen. Accounts bekommen nur Admins. |
| `bb.contentpull.sessionTtl` | Quelle | `7200` | Gültigkeit des Session-Tokens nach einem Kirby-Login in Sekunden. |
| `bb.contentpull.accounts` | Quelle | `false` | Erlaubt, `site/accounts` auszuliefern (für `--accounts`). |
| `bb.contentpull.source` | Ziel | – | Basis-URL der Quelle, z. B. `https://www.example.com`. |
| `bb.contentpull.timeout` | Ziel | `120` | HTTP-Timeout pro Request in Sekunden. |
| `bb.contentpull.verify` | Ziel | `true` | TLS-Zertifikat der Quelle prüfen. Nur für lokale Quellen mit Self-Signed-Zertifikat auf `false`. |
| `bb.contentpull.hashCache` | beide | `true` | Hashes unveränderter Dateien (gleiche Größe + mtime) wiederverwenden, siehe [Caches](#caches). |
| `bb.contentpull.cache` | Ziel | – | Verzeichnis eines gemeinsamen Datei-Caches mehrerer Ziel-Umgebungen, siehe [Caches](#caches). |
| `bb.contentpull.updates` | beide | `false` | Update aus dem Panel anbieten, siehe [Plugin-Updates](#plugin-updates). |
| `bb.contentpull.updateRepository` | beide | `bold-and-bright/bb-content-pull` | GitHub-Repository der Releases. |
| `bb.contentpull.updateApi` | beide | `https://api.github.com` | API-Basis-URL (z. B. GitHub Enterprise). |

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
kirby content:pull [--scope=texts|media|all|accounts] [--page=<id>] [--recursive]
                   [--lang=de,en] [--missing-only] [--max-size=20M]
                   [--delete] [--dry-run] [--accounts] [--include-changes]
                   [--archive] [--json]
                   [--user=<email> [--password=<pw>] | --login]
```

| Option | Beschreibung |
| --- | --- |
| `--scope` | `texts` = alle `*.txt` inkl. Datei-Metadaten (`bild.jpg.de.txt`), `media` = alle anderen Dateien, `all` (Default), `accounts` = nur `site/accounts`, ohne Content. |
| `--page` | Nur dieser Seitenordner, als Kirby-Page-ID (`ueber-uns/team`). Sortiernummern und `_drafts` werden ignoriert, Entwürfe sind also adressierbar. `site` = nur `site.*.txt` und Site-Dateien. |
| `--recursive` | Mit `--page`: inkl. aller Unterseiten. `--page=site --recursive` = alles. |
| `--lang` | Nur Textdateien dieser Sprachen. Medien sind sprachunabhängig und bleiben drin. |
| `--missing-only` | Nur Dateien holen, die lokal fehlen; vorhandene werden nicht verglichen. |
| `--max-size` | Medien oberhalb dieser Größe überspringen (`500K`, `20M`, `1G`). Textdateien nie. |
| `--delete` | Lokale Dateien **im gewählten Scope** löschen, die es in der Quelle nicht gibt. Leere Seitenordner werden entfernt. Default aus. |
| `--dry-run` | Nur die Diff-Liste ausgeben: neu / geändert / gelöscht / übersprungen. |
| `--accounts` | Zusätzlich zum Content `site/accounts` syncen. Accounts werden immer komplett übernommen, Scope/Page/Lang gelten nur für Content. Nur Accounts: `--scope=accounts`. Beides braucht `bb.contentpull.accounts` auf der Quelle. |
| `--include-changes` | Kirby-5-`_changes`-Ordner (ungespeicherte Panel-Änderungen) mitnehmen. |
| `--archive` | Das gefilterte Set als ein ZIP holen statt Datei für Datei – sinnvoll für viele Medien. Übernommen werden trotzdem nur die Dateien aus dem Diff. Braucht `ext-zip` auf beiden Seiten. |
| `--json` | Ein JSON-Dokument auf stdout statt Fortschrittsausgabe. |
| `--user` | Statt des Tokens mit diesem Kirby-Benutzer (E-Mail) der Quelle anmelden. Ohne `--password` wird das Passwort verdeckt abgefragt. |
| `--password` | Passwort zu `--user`. Besser weglassen und abfragen lassen – Argumente landen in der Shell-History und sind in der Prozessliste sichtbar. |
| `--login` | E-Mail und Passwort abfragen. |

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

# Nur die Benutzerkonten (z. B. für den Panel-Login auf Stage)
kirby content:pull --scope=accounts

# In DDEV
ddev exec vendor/bin/kirby content:pull --scope=texts
```

### Mit Kirby-Zugangsdaten statt Token

Entwickler:innen können mit ihrem eigenen Kirby-Account der Quelle ziehen – der Token muss dann auf dem Ziel gar nicht bekannt sein:

```bash
# Passwort wird verdeckt abgefragt
kirby content:pull --user=dev@example.com --dry-run

# E-Mail und Passwort abfragen
kirby content:pull --login

# Nicht-interaktiv (z. B. Skripte, Claude) – Passwort landet in der Shell-History
kirby content:pull --user=dev@example.com --password='…' --json
```

Ablauf: Die CLI meldet sich einmal per `POST /bb-content-pull/login` an (HTTP Basic, Fallback-Header `X-Content-Pull-Auth`). Die Quelle prüft über Kirbys `Auth::validatePassword()` – inklusive Sperre nach zu vielen Fehlversuchen und Verzögerung bei falschem Passwort – und gibt einen signierten Session-Token zurück (Standard 2 Stunden), mit dem alle weiteren Requests laufen. Der Token ist mit dem Passwort-Hash des Benutzers signiert: Ändert sich das Passwort, ist er sofort ungültig. Erlaubt sind nur Rollen aus `bb.contentpull.roles` (Standard: Admins); Accounts bekommen nur Admins. Eine zweite Stufe (Login-Code) wird dabei nicht abgefragt. Abfragen erscheinen auf stderr, `--json` auf stdout bleibt sauber. Im Log steht der Benutzer: `[cli:dev@example.com]`.

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

Ohne `--dry-run` enthält `result` die Listen `written`, `deleted`, `failed` (`[{path, error}]`), `cached` (Anzahl der Dateien aus dem gemeinsamen Cache) und `duration`. Bei einem Abbruch: `{"ok": false, "error": "…"}` und Exit-Code 1.

`skipped.reason` ist `size` (über `--max-size`) oder `invalid path` (von der Quelle gelieferter Pfad, der die Validierung nicht besteht – wird nie geschrieben).

### Nach dem Pull

- `site/cache` und `media/` werden geleert (Kirby-Cache-API bzw. `Kirby\Filesystem\Dir`). `media/panel`, `media/plugins` und Dotfiles (`.gitkeep`, `.htaccess`) bleiben – sonst bricht ein gerade geöffnetes Panel ab bzw. verschwinden versionierte Platzhalter.
- Jeder Lauf wird in `site/logs/content-pull.log` protokolliert: Start mit Optionen, jede geschriebene / gelöschte / fehlgeschlagene Datei, Abschluss.
- Es läuft immer nur ein Pull pro Installation gleichzeitig (Lock-Datei im System-Temp).

## Caches

### Hash-Cache (Quelle und Ziel)

Ohne Cache liest die Quelle für jedes Manifest alle Dateien im Scope komplett, um ihren SHA-1 zu berechnen – bei einer großen Medienbibliothek bei jedem Pull Hunderte MB. Der Hash-Cache merkt sich pro Pfad Größe, mtime und Hash in `site/cache/bb-content-pull/hashes.json` (bzw. dem `cache`-Root) und hasht nur Dateien neu, die sich geändert haben. Auf dem Ziel gilt dasselbe für den lokalen Vergleich. Der Ordner bleibt beim Leeren des Caches nach einem Pull erhalten.

Standardmäßig an; abschalten mit `'bb.contentpull.hashCache' => false`.

### Gemeinsamer Datei-Cache (Ziel)

Laufen mehrere Ziel-Umgebungen auf demselben Server – etwa Stage und PR-Previews –, holt sonst jede alle Medien selbst von der Quelle. Mit

```php
'bb.contentpull.cache' => '/var/cache/content-pull',
```

legt jede Umgebung heruntergeladene Dateien zusätzlich unter ihrem SHA-1 ab (`/var/cache/content-pull/ab/abcdef…`). Vor jedem Download wird zuerst dort nachgesehen; Treffer werden kopiert und gegen den Hash aus dem Manifest geprüft, beschädigte Einträge verworfen und neu geladen. So überträgt die Quelle jede Datei nur einmal, egal wie viele Umgebungen sie brauchen.

Das Verzeichnis muss für alle Umgebungen dasselbe sein (z. B. ein Bind-Mount vom Host in jeden Container) und für den PHP-User beschreibbar. Aufräumen ist nicht nötig, solange der Platz reicht; sonst kann der Ordner jederzeit geleert werden.

## Plugin-Updates

Zwei Wege, je nach Projekt:

**Mit Git-Deploy (empfohlen):** Plugin per Composer einbinden und Updates als Pull Request kommen lassen (z. B. über einen Composer-Update-Bot, Dependabot oder Renovate). Ein Update im Panel würde vom nächsten Deploy überschrieben.

**Ohne Git-Deploy (optional):** Update direkt aus dem Panel oder per CLI, aus den GitHub-Releases dieses Repositories.

```php
'bb.contentpull.updates' => true,
```

- **Panel:** Kein eigener Menüpunkt – unter *System → Plugins* zeigt die Versionsspalte von `bb/content-pull` den Update-Status (wie bei Kirbys eigenen Update-Hinweisen). Das Dropdown führt über „Informationen zur Version“ zur Ansicht *System › Content Pull Update* mit Release-Notes, „Aktualisieren“ und „Vorherige Version wiederherstellen“. Ohne die Option bleibt die Systemseite unverändert.
- **CLI** (unabhängig von der Option):
  ```bash
  kirby content-pull:update --check        # installierte und neueste Version
  kirby content-pull:update                # auf die neueste Version
  kirby content-pull:update --version=1.4.0
  kirby content-pull:update --rollback     # vorherige Version zurück
  ```

Ablauf: Release über die GitHub-API ermitteln (6 Stunden gecacht), `bb-content-pull.zip` und `bb-content-pull.zip.sha256` laden, Prüfsumme vergleichen, nur Einträge unterhalb von `bb-content-pull/` entpacken (keine `..`, keine Symlinks), dann per `rename` austauschen. Die bisherige Version bleibt als `site/plugins/.bb-content-pull.backup` liegen – Kirby lädt Ordner mit führendem Punkt nicht. Danach wird der Opcache geleert; jedes Update steht im Log.

Voraussetzungen: öffentliches Repository (die API wird ohne Token abgefragt), PHP darf in `site/plugins` schreiben, `ext-zip`. Das Panel weist darauf hin, wenn das Projekt ein Git-Checkout ist oder das Plugin per Composer installiert wurde.

### Release erstellen

1. `version` in `composer.json` erhöhen und committen.
2. Taggen und pushen: `git tag v1.4.0 && git push origin v1.4.0`.
3. Der Workflow `.github/workflows/release.yml` führt die Tests aus, prüft Tag gegen `composer.json`, baut das ZIP per `git archive` (ohne Tests und CI-Dateien, siehe `.gitattributes`) samt SHA-256 und hängt beides an das GitHub-Release.

## Quell-Endpunkte

Nur aktiv mit `bb.contentpull.serve => true`. Authentifizierung per `Authorization: Bearer <token>` – gemeinsamer Token oder Session-Token aus `/login`; als Fallback für Hoster, die den Authorization-Header verwerfen, auch `X-Content-Pull-Token: <token>`. Ohne gültigen Token: `401`.

| Endpunkt | Beschreibung |
| --- | --- |
| `GET /bb-content-pull/manifest` | JSON `{generated, languages, files: [{path, sha1, size, mtime}]}`. Akzeptiert `scope`, `page`, `recursive`, `lang`, `accounts`, `include-changes`; `hash=0` liefert eine billige Liste ohne Hashes. |
| `GET /bb-content-pull/file?path=…` | Einzelne Datei, immer als `application/octet-stream`-Download, gestreamt. |
| `GET /bb-content-pull/archive?…` | ZIP des gefilterten Sets (gleiche Parameter wie `manifest`, zusätzlich `max-size`). Medien unkomprimiert gespeichert. |
| `POST /bb-content-pull/login` | Kirby-Zugangsdaten per HTTP Basic (oder `X-Content-Pull-Auth: base64(email:passwort)`) → `{token, expires, user, role}`. Der Token gilt dann wie der gemeinsame Token. |

```bash
curl -H "Authorization: Bearer $TOKEN" "https://www.example.com/bb-content-pull/manifest?scope=texts&page=ueber-uns"
```

## Sicherheit

- Alle Pfade – in Anfragen an die Quelle **und** im Manifest, das das Ziel empfängt – laufen durch `Paths::normalize()`: keine absoluten Pfade, kein `..`/`.`, keine Backslashes, Steuerzeichen oder Laufwerksbuchstaben, nur unterhalb von `content/` (bzw. `accounts/`, wenn erlaubt).
- `Paths::resolve()` prüft zusätzlich per `realpath`, dass das Ziel innerhalb des Roots liegt; Symlinks nach außen werden abgelehnt. Die Quelle liefert keine Symlinks aus.
- ZIP-Archive werden nie blind entpackt, sondern Eintrag für Eintrag per Name gelesen und gegen den Hash aus dem Manifest geprüft.
- Token-Vergleich mit `hash_equals`. Ausgelieferte Dateien sind immer Downloads (`nosniff`), nichts wird gerendert.
- `site/accounts` enthält Passwort-Hashes: `bb.contentpull.accounts` nur aktivieren, wenn das wirklich gebraucht wird, und das Ziel (Stage, Previews) nicht öffentlich zugänglich machen. Mit gemeinsamem Datei-Cache liegen die Account-Dateien auch dort.
- Ohne `bb.contentpull.accounts` lehnt die Quelle jede Anfrage mit Accounts ab (`403`).

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
| `HashCache` | Hashes unveränderter Dateien wiederverwenden |
| `ObjectCache` | Gemeinsamer, inhaltsadressierter Datei-Cache |
| `Server` | Quell-Endpunkte inkl. Kirby-Login |
| `SessionToken` | Signierter, kurzlebiger Token nach dem Kirby-Login |
| `Updater`, `UpdateApi`, `UpdateCommand` | Optionales Self-Update aus GitHub-Releases (Panel + CLI) |
| `Command` | `kirby content:pull` |
