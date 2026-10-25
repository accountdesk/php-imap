# CLAUDE.md

## Kommunikation
- Mit dem User **auf Deutsch** kommunizieren.
- **Code, Kommentare, Commit-Messages, Tests und CHANGELOG auf Englisch** – das Projekt ist ein Fork einer
  englischsprachigen Library und soll upstream-kompatibel bleiben.

## Was das ist

Fork von [`webklex/php-imap`](https://github.com/Webklex/php-imap) (reiner PHP-IMAP-Client, kein ext-imap
nötig) für **AccountDesk**. ⚠️ **Maßgeblich ist der Betrieb OHNE ext-imap** (wird nicht mehr sinnvoll gepflegt,
seit PHP 8.4 nicht gebündelt) – wo sich Verhalten mit/ohne ext-imap unterscheidet, ist „ohne“ die Referenz. Das Paket behält den Namen `webklex/php-imap`; `../accountdesk` bindet es per
`vcs`-Repository `https://github.com/accountdesk/php-imap` mit `"webklex/php-imap": "^6.2"` ein und
nutzt vor allem `Folder::idle()` für die Mail-Überwachung.

### Branches, Remotes, Releases
- `origin` = `accountdesk/php-imap` (unser Fork), `upstream` = `Webklex/php-imap`.
- `master` = Stand von upstream. Nicht für eigene Änderungen verwenden.
- `accountdesk` (auf `origin`) = **Release-Branch**, von dem AccountDesk installiert.
- Arbeit in Feature-Branches nach dem Schema `feat/…` / `fix/…`, Basis ist `origin/accountdesk`;
  danach Merge in `accountdesk`.
- Releases sind **vierstellige Tags** auf `accountdesk`: `6.2.0.N` (zuletzt `6.2.0.9`). Ein neues Tag ist nötig,
  damit `composer update webklex/php-imap` in `../accountdesk` die Änderung holt.
- Push, Merge in `accountdesk` und Tags nur nach Rückfrage – sie landen direkt in der Produktion von AccountDesk.
- ⚠️ **Beim Neuaufbau von `accountdesk` keine Commits verlieren**: Für `6.2.0.4` wurde der Branch neu auf upstream
  aufgebaut, dabei fielen 16 Fixes aus `6.2.0.2`/`6.2.0.3` weg (u. a. PEEK beim Body-Abruf, #531, MIME ohne
  ext-imap, b6a0074) – wiederhergestellt auf `feat/idle-test-matrix` (2026-10-24). Vorher immer
  `git cherry -v <neuer Stand> <alter Release-Tag>` prüfen: jede `+`-Zeile fehlt im neuen Stand.

## Struktur

- `src/Client.php`, `ClientManager.php`, `Config.php` – Verbindung/Konfiguration (Defaults in `src/config/imap.php`)
- `src/Folder.php` – Ordner, inkl. **`idle()`** (Hauptbaustelle des Forks, siehe unten)
- `src/Message.php`, `Header.php`, `Structure.php`, `Part.php`, `Attachment.php`, `Address.php`, `Attribute.php` – Parsing
- `src/Query/` – Such-Builder (`WhereQuery`)
- `src/Connection/Protocols/ImapProtocol.php` – IMAP über Sockets (Standard); `LegacyProtocol.php` nutzt ext-imap
- `src/Decoder/` – Header-/Message-/Attachment-Decoding
- `tests/` – Unit-Tests; `tests/fixtures/` (`.eml` aus `tests/messages/`), `tests/issues/` (Regressionen zu
  Upstream-Issues), `tests/live/` (braucht echten Mailserver, `LIVE_MAILBOX=true`)
- `tests/support/` – Fake-Server und Helfer (siehe Tests)
- `tests/servers/` – Docker-Matrix echter IMAP-Server, `.github/workflows/tests.yaml` – CI

## Tests

Lokal ist **kein PHP** installiert. Alles läuft in Docker, im Test-Image `php-imap-test:<PHP_VERSION>`
(`tests/docker/php.Dockerfile`, PHP + pcov, wird bei Bedarf automatisch gebaut).

### Offline (immer, schnell genug für jede Änderung)
```sh
.claude/scripts/phpunit.sh                                  # alles (Live-Tests werden übersprungen), ~2 min
.claude/scripts/phpunit.sh tests/IdleTest.php               # gezielt (PHPUnit 9: nur EIN Pfad-Argument)
.claude/scripts/phpunit.sh --filter 'testA|testB' tests     # mehrere Dateien/Tests über --filter
.claude/scripts/phpunit.sh --coverage                       # + build/coverage.txt, HTML in build/coverage/
PHP_IMAP=1 .claude/scripts/phpunit.sh                       # Nebenprüfung MIT ext-imap (Image php-imap-test:8.3-imap)
```
- **Baseline (Stand 2026-10-25): der Gesamtlauf hat KEINEN Fehlschlag** – ohne und mit ext-imap (803 Tests).
  Jeder rote Test ist eine Regression.
- Coverage-Stand 2026-10-25: **89 % Zeilen** offline (Start 54 %). Ungedeckt sind fast nur ext-imap-/mailparse-Zweige
  und LegacyProtocol.
- **`BUG:`-Tests**: Ein gefundener, noch nicht behobener Bug wird als Test geschrieben, der das korrekte Verhalten
  prüft, und mit `markTestSkipped("BUG: …")` versehen (`grep -rn '"BUG:' tests`; aktuell keiner offen). Wer den Bug
  behebt, entfernt das Skip.
- Fake-Server und Helfer (`tests/support/`):
  - `idle_server.php` – skriptbarer IDLE-Server (Szenarien als JSON: Push ja/nein, EXISTS-Echo, EXPUNGE, BYE,
    freeze/blackhole = stumm tote Verbindung, outage = Server-Neustart, UIDVALIDITY-Wechsel, SELECT ohne UIDNEXT …),
    Tests in `tests/IdleTest.php`. **IDLE-Probleme hier als Szenario nachstellen.**
  - `scripted_imap_server.php` + `ScriptedServerTestCase.php` – Dialog-Skript „erwartete Zeile → Antwort“ für
    Protokoll-, Client- und Folder-Tests (`tests/ImapProtocol*Test.php`, `ClientServerTest`, `FolderServerTest`,
    `ServerFailureTest` = kaputte/feindliche Antworten, `ImapProtocolFuzzTest` = deterministischer Fuzz-Test).
  - `connect_proxy.php` – Fake-HTTP-CONNECT-Proxy für die Proxy-Option.
- Bugfixes bekommen einen Test, der **ohne den Fix fehlschlägt** (Gegenprobe machen).

### Live gegen echte Server (Docker-Matrix, `tests/servers/`)
```sh
.claude/scripts/imap-servers.sh up [server…]                # Dovecot 2.3/2.4, GreenMail, Cyrus, Stalwart, James, Courier + Toxiproxy
.claude/scripts/imap-servers.sh live dovecot23              # komplette Live-Suite (tests/live + Live-Issues) gegen ein Target
.claude/scripts/imap-servers.sh live courier --filter testMove
.claude/scripts/imap-servers.sh test docker tests/live/IdleLiveTest.php   # nur IDLE gegen alle Targets (~10 min)
.claude/scripts/imap-servers.sh logs <server> | down
```
- Targets: `tests/servers/targets.php`. **Server-Eigenheiten sind Capabilities** (`capabilities`, Liste mit Defaults in
  `ImapTargets::CAPABILITIES`, z. B. `id`, `children`, `uidnext_in_select`, `search_message_id_header`); Live-Tests
  prüfen sie mit `$this->requiresCapability("…", "Grund")` – **nie pauschal pro Server skippen**, und nie, um einen
  Library-Bug zu verdecken (erst prüfen, ob die Library sich korrekt verhält, z. B. per Probe-Skript gegen den Server).
- Neue Live-Tests legen ihren Ordner mit `createTestFolder()` an (keine Annahme „INBOX leer“). Live-Issue-Tests
  in `tests/servers/phpunit-live.xml` eintragen.
- Echte Provider-Accounts (GMX, web.de, Gmail, Outlook/OAuth) kommen nach `tests/servers/targets.local.php`
  (gitignored, Vorlage `.dist`), dann `IMAP_TARGETS=gmx,…` bzw. `imap-servers.sh test gmx …`.
- `tests/live/IdleLiveTest.php`: eigener Ordner pro Test, `tests/support/idle_injector.php` ändert parallel per
  APPEND/Delete/Flag und stört über Toxiproxy (Proxy aus = Verbindungsabbruch, Blackhole = stumm tote Verbindung).
  Langlauf: `IDLE_SOAK_MINUTES=35` (+ optional `IDLE_SOAK_TIMEOUT`).

### CI (`.github/workflows/tests.yaml`)
- Checks „Unit tests (PHP 8.3 / 8.5, offline)“: Offline-Gesamtlauf mit Coverage im Test-Image ohne ext-imap auf den
  PHP-Versionen der AccountDesk-Server (24.04 = 8.3, 26.04 = 8.5), dazu `php -l` aller Dateien mit PHP 8.0.
- Je ein Check „IMAP: <Server>“ (PHP 8.5): Server + Toxiproxy per `imap-servers.sh`, komplette Live-Suite gegen alle Targets
  des Servers. JUnit/Ausgabe als Artifact (`gh run download <run> -n imap-<server>-reports`), Summary pro Check.

## Konventionen

- PHP `^8.0.2` – keine Features neuer als PHP 8.0 (CI prüft die Syntax mit 8.0, getestet wird mit 8.3 und 8.5).
  Keine Deprecations unter 8.5 (auch nicht in Tests: `setAccessible()` nur für PHP < 8.1).
  Parameter mit `null`-Default immer explizit nullable (`?Type $x = null`, PHP 8.4).
- Stil wie im Bestand: 4 Spaces, öffnende Klammer auf derselben Zeile, `snake_case` für Variablen/Optionen,
  `camelCase` für Methoden, PHPDoc mit `@throws` an öffentlichen Methoden, Datei-Header-Kommentar bei neuen Dateien.
- Kein Formatter/Linter im Repo – keinen Massen-Reformat machen, Diffs klein halten (Upstream-Merges).
- Öffentliche API von `Folder::idle()` & Co. abwärtskompatibel erweitern (neue optionale Parameter am Ende),
  weil `../accountdesk` darauf aufsetzt.
- Bewusste API-Entscheidungen (Stand 2026-10-25): `store()` mit `$mode = null` **ersetzt** die Flags; `Folder::move()`/
  `rename()` erwarten UTF-8 (oder `$utf7 = true`); `Folder::appendMessage()` normalisiert Zeilenenden auf CRLF;
  MOVE wird nur bei angekündigter Capability gesendet, sonst COPY/STORE/EXPUNGE; Proxy-Option = HTTP CONNECT;
  Adressen parst die Library selbst (auch mit ext-imap).
- Commit-Messages englisch, Betreff im Imperativ/als Kurzbeschreibung (`Fix Folder::idle() …`,
  `idle(): …`), Body erklärt Ursache, Wirkung und wie der Test es nachstellt.

## `Folder::idle()` – worauf achten

- Während die Session im IDLE ist, darf **nur `DONE`** gesendet werden (rfc2177). Kein `NOOP`, also auch kein
  `Client::getConnection()` innerhalb der Schleife (das prüft mit NOOP).
- Keep-Alive = `DONE` + erneutes `IDLE` nach festem Intervall; optionaler `$on_keepalive`-Callback, `$max_runtime`.
- `EXISTS` meldet die **Mailbox-Größe**, nicht „neue Nachricht“ – Server wiederholen sie (GreenMail nach jedem
  DONE). Nur eine größere Zahl ist neu; zusätzlich schützt UIDNEXT vor Doppelzustellung.
- Untagged Antworten während des DONE/IDLE-Wechsels werden aufbewahrt
  (`ImapProtocol::pullIdleUntaggedResponses()`), nicht verworfen.
- Nach Reconnect: Nachrichten zwischen altem und neuem UIDNEXT nachliefern; bei UIDVALIDITY-Wechsel neu aufsetzen.
- Reconnect mit Backoff (1, 2, 4 … 30 s), bis zu max(timeout, 60) s bzw. bis `$max_runtime` – danach wirft idle().
- Die Lese-Wartezeit ist durch Keep-Alive-Intervall **und** Restlaufzeit begrenzt (`$max_runtime` wird eingehalten).
- Courier liefert bei SELECT kein UIDNEXT – idle() (und `Message::copy()/move()`) fragen es dann per STATUS ab.
- Ein Reconnect, dessen LOGIN unbeantwortet bleibt (Netz noch tot), wird wiederholt; ein abgelehntes LOGIN nicht.
- Rückgabe sind Laufzeit-Statistiken (`messages`, `keepalives`, `reconnects`, `checks`, `runtime`).
