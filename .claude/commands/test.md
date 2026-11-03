---
description: Tests im Docker-Test-Image ausführen und mit der bekannten Baseline vergleichen
argument-hint: "[--coverage] [Testdatei/-verzeichnis] [--filter name] | live [targets]"
---

Argumente: `$ARGUMENTS`

- Ohne Argumente bzw. mit Pfad/Filter: `.claude/scripts/phpunit.sh $ARGUMENTS`
- Beginnt es mit `live`: Docker-Matrix nutzen – `.claude/scripts/imap-servers.sh up` (falls nicht läuft), dann
  `.claude/scripts/imap-servers.sh test <targets, Standard docker> tests/live/IdleLiveTest.php`.

Auswertung:
- Mit der Baseline in `CLAUDE.md` (Abschnitt „Tests“) vergleichen und nur Abweichungen als Regression melden.
  Bei Unklarheit die betroffene Datei gezielt mit voller Ausgabe laufen lassen und per `git stash` gegenprüfen.
- Bei `--coverage` die Summary aus `build/coverage.txt` nennen und auffällige Rückgänge pro Datei.
- Knapp auf Deutsch berichten: was grün ist, was neu rot ist (mit Fehlermeldung).
