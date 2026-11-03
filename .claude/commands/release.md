---
description: Feature-Branch in accountdesk mergen, neues 6.2.0.N-Tag setzen und AccountDesk-Update vorbereiten
argument-hint: "[Branch, Standard: aktueller Branch]"
---

Release-Ablauf für den AccountDesk-Fork. **Vor jedem Push/Tag explizit beim User nachfragen** – das Tag wird
von `../accountdesk` per Composer installiert.

1. `git fetch origin --tags`. Branch: `$ARGUMENTS` oder der aktuelle Branch.
2. Prüfen: Arbeitsverzeichnis sauber, Branch basiert auf `origin/accountdesk` (`git log origin/accountdesk..<branch>`
   zeigt nur die neuen Commits), Commit-Messages englisch.
3. `.claude/scripts/phpunit.sh` – keine Fehlschläge über die Baseline in `CLAUDE.md` hinaus.
4. Nächstes Tag bestimmen: höchstes `6.2.0.N` aus `git tag --list '6.2.0.*' | sort -V | tail -1`, N + 1.
5. Dem User zeigen: Commits, die in `accountdesk` landen, und das geplante Tag. Erst nach Bestätigung:
   - `accountdesk` auf den Branch fast-forwarden (ggf. Merge-Commit wie bisher: `Merge branch '<branch>' into accountdesk`),
   - `git push origin accountdesk` und den Branch pushen,
   - Tag setzen (`git tag 6.2.0.N`) und `git push origin 6.2.0.N`.
6. `CHANGELOG.md` nur anfassen, wenn der User das möchte (er folgt dem Upstream-Format).
7. Am Ende den Befehl für AccountDesk ausgeben (nicht selbst ausführen):
   `cd ../accountdesk && composer update webklex/php-imap`
