# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository-Kontext

Fork von [Webklex/php-imap](https://github.com/Webklex/php-imap) (PHP-IMAP-Client ohne ext-imap-Pflicht) unter `accountdesk/php-imap`. Arbeitsweise in diesem Fork: Fixes/Features werden auf `fix/*`- bzw. `feat/*`-Branches entwickelt und in den Integrations-Branch `accountdesk` gemerged. `master` folgt dem Upstream.

## Befehle

```bash
composer install
composer test                                  # kompletter PHPUnit-Lauf
vendor/bin/phpunit tests/fixtures/BccTest.php  # einzelne Testdatei
vendor/bin/phpunit --filter test_method_name   # einzelner Test
```

- Anforderungen: PHP ^8.0.2, PHPUnit ^9.5.

### Statische Analyse / Linting (Mago)

[Mago](https://mago.carthage.software) ist als Toolchain integriert (Config: `mago.toml`, PHP-Zielversion 8.0.2):

```bash
composer analyze       # mago analyze  — statische Analyse (nur NEUE Issues)
composer lint          # mago lint     — Stil-/Best-Practice-Regeln
composer format-check  # mago format --check — Formatprüfung ohne Schreiben
composer format        # mago format   — Formatierung anwenden
```

Der bestehende Analyse-Altbestand ist in `mago-baseline.php` eingefroren, damit `mago analyze` nur bei *neuen* Befunden fehlschlägt. Nach Abbau von Schuld neu erzeugen mit `mago analyze --baseline mago-baseline.php --generate-baseline`.
- Live-Tests (`tests/live/`) sind über die Env-Variable `LIVE_MAILBOX` gesteuert und standardmäßig deaktiviert (skippen sich selbst). Zum Aktivieren `phpunit.xml.dist` nach `phpunit.xml` kopieren und die `LIVE_MAILBOX_*`-Variablen setzen; ein Test-IMAP-Server (Dovecot) liegt als Dockerfile unter `.github/docker/`. Achtung: Live-Tests löschen Daten im Testpostfach.

## Architektur

Kette vom Einstieg bis zur Nachricht:

`ClientManager` (Account-Verwaltung) → `Config` (Dot-Notation, Defaults aus `src/config/imap.php`) → `Client` (Verbindungs-Lifecycle, Folder-Operationen) → `Connection/Protocols/*` → `Folder` → `Query`/`WhereQuery` (fluenter IMAP-SEARCH-Builder, Fetching, Pagination) → `MessageCollection` → `Message`.

**Protokollschicht** (`src/Connection/Protocols/`): Kernstück der Bibliothek.
- `ImapProtocol` — vollständige eigene IMAP-Implementierung auf Socket-Ebene (inkl. IDLE mit `max_runtime`/Keep-alive gemäß RFC 2177, OAuth). Hier landen die meisten Protokoll-Fixes dieses Forks.
- `LegacyProtocol` — Wrapper um die PHP-Extension `imap`; nur nötig für POP3/NNTP oder Edge-Cases.
- `Response` — kapselt Request/Response-Daten und Validierung; Protokollmethoden geben `Response`-Objekte zurück, nicht rohe Arrays.

**Message-Parsing**: `Message` wird aus `Header` + `Structure` → `Part`s aufgebaut; `Attachment` hält eine schwache Referenz auf die Message (Memory-Fix #531). Header-Werte sind `Attribute`-Objekte. Dekodierung läuft über austauschbare Decoder in `src/Decoder/` (Header/Message/Attachment, per Config `decoder.*` konfigurierbar) plus `EncodingAliases` für Charset-Mapping. `Message::fromFile()` parst .eml-Dateien ohne Serververbindung — Grundlage der Fixture-Tests.

**Events** (`src/Events/`): Bei Aktionen wie move/copy/delete/restore werden Events über den Trait `HasEvents` dispatcht; eigene Event-Klassen können per Config/`setEvent()` registriert werden.

**Masks** (`src/Support/Masks/`): Dekorator-Klassen für `Message`/`Attachment`, per Config (`masks.*`) austauschbar.

## Tests-Struktur

- `tests/*.php` — Unit-Tests der Kernklassen (u. a. `ImapProtocolTest`).
- `tests/fixtures/` + `tests/messages/*.eml` — Parsing-Tests: `FixtureTestCase::getFixture()` lädt eine .eml offline über `Message::fromFile()`. Neue Parsing-Fälle bekommen eine .eml in `tests/messages/` und einen Test in `tests/fixtures/`.
- `tests/issues/` — Regressionstests, benannt nach Upstream-GitHub-Issue (`Issue511Test.php`).
- `tests/live/` — Tests gegen echten IMAP-Server (siehe oben).
