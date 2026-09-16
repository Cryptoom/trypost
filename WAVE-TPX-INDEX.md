# Master-Index · Welle TPX (TryPost eXtension)

> Plan: `~/.claude/plans/proud-bubbling-dewdrop.md` (REVISION 1, 16.09.2026)
> Orchestrator-Session: TPX-00 (Origin: playcraft-toys, gespawnt 16.09.2026)
> Hinweis: `docs/` ist in diesem Repo gitignored (`.gitignore:33`), darum liegt dieser
> Master-Index versioniert im Repo-Root statt unter `docs/plans/`.
> Push-Gate-Klaerung (16.09.2026, Olli): Chips pushen ihren eigenen Branch + eroeffnen den PR
> autonom, ohne Rueckfrage. Merge nach main und Deploy auf web02 bleiben harte Olli-Gates.
> Nachtmodus aktiv seit 2026-09-17 (Nacht-Gate im Plan, Gate-File `~/.claude/nachtgate/
> proud-bubbling-dewdrop`): Merge nach main laeuft waehrend des Nachtmodus AUTONOM nach 2
> gruenen Review-Runden, echte Olli-Gates (YouTube-Scope, Threads-Review, B4-Produktfrage, U1,
> Deploy, Live-Smoke-Tests B2a-d) bleiben harte Stopps. Kette laeuft bis zum naechsten echten
> Gate durch, auch ueber Welle-A-Ende hinaus.

Status-Symbole: ✓ done · 🚀 deployed · ⏳ in_progress · ❓ waiting · 🔄 pending · ⛔ blocked ·
❌ failed · 🔍 review · 📝 discovered

## Active Chips

| Chip | Paket | Status | Started | Worktree/Branch |
|---|---|---|---|---|
| TPX-07 | A4 · MCP-Tool-Parameter | ⏳ in_progress | 2026-09-17 | claude/tpx-07-a4-mcp |
| TPX-08 | A5 · Vue-UI | 🔍 review-Fix (2 echte Findings, PR #12 nicht gemergt) | 2026-09-17 | claude/tpx-08-a5-vue-ui |

## Pending (Startreihenfolge)

| Chip | Paket | Status | Dependencies | Branch/PR | Plan/Real |
|---|---|---|---|---|---|
| TPX-07 | A4 · MCP-Tool-Parameter | ⏳ (siehe oben) | ✓ A2 gemergt | - | - |
| TPX-08 | A5 · Vue-UI | ⏳ (siehe oben) | ✓ A1 gemergt | - | - |
| TPX-09 | B0 · Nachweis-Paket | 🔄 pending | Welle A komplett gemergt | - | - |
| TPX-10 | B1 · UnpublishPost + DeletePost | 🔄 pending | B0 | - | - |
| TPX-11 | B2a · Facebook/Instagram delete() | 🔄 pending | B0, B1, A3 | - | - |
| TPX-12 | B2b · Threads delete() | 🔄 pending | B0, B1, A3, Threads-Gate | - | - |
| TPX-13 | B2c · LinkedIn delete() | 🔄 pending | B0, B1, A3 | - | - |
| TPX-14 | B2d · YouTube delete() | 🔄 pending | B0, B1, A3, YouTube-Scope-Gate | - | - |
| TPX-15 | B3 · MCP UnpublishPostTool | 🔄 pending | B1 | - | - |
| TPX-16 | B4 · Vue-UI + Web-Route | 🔄 pending | B1, B4-Produktfrage | - | - |
| TPX-17 | U1 · Upstream-PR-Vorbereitung | 🔄 pending | Welle B gemergt + deployed | - | - |

## Completed

| Chip | Paket | PR | Merge-Commit | Notiz |
|---|---|---|---|---|
| TPX-04 | A1 · Migration + Model + Media-ID-Fix | [#9](https://github.com/Cryptoom/trypost/pull/9) | `877a2a9c` | `media_post_platform`-Pivot (uuid), `MediaPostPlatform`-Custom-Pivot-Model (`HasUuids`), `PostPlatform::media()`+`scopedMediaItems()`. TPX-03-Fix workspace-gescoped ueber `medias.mediable_type`/`mediable_id` (polymorph, KEINE literale workspace_id-Spalte, Abweichung vom Briefing-Wortlaut zugunsten der verifizierten TPX-03-Implementierung). 975/975 Post-Tests, 367/367 Mcp-Tests, 2 Review-Runden PASS, autonom gemergt (Nachtmodus) |
| TPX-06 | A3 · Publisher-Rollout | [#10](https://github.com/Cryptoom/trypost/pull/10) | `f411d274` | 12 Dateien geaendert (Plan-Liste nannte nur 9, `DiscordPublisher.php`+`TelegramPublisher.php` fehlten in der Plan-Liste, per Grep gefunden und mitgefixt), 16 Call-Site-Ersetzungen. `mediaSnapshot()` bewusst unveraendert. 420/420 Publisher-Tests gruen, 1 Review-Runde PASS, autonom gemergt |
| TPX-05 | A2 · Validierungs-Umbau | [#11](https://github.com/Cryptoom/trypost/pull/11) | `7b73cf56` | `ContentTypeCompatibleWithMedia::entriesForUpdate()` loest pro Plattform eigene Media-Liste auf (Request-Media > scopedMediaItems() > volle Post-Liste). Randfall (Media-Pflicht, Liste leer) bleibt korrekt ein Fehler. Web-Aufrufstelle auf `after()`-Validator umgestellt (schliesst nebenbei eine API-Luecke). Lazy-Loading-Bug in scopedMediaItems() unter shouldBeStrict() nebenbei gefixt. 849/850 breiter Sweep gruen, 2 Review-Runden PASS. Koordinierte Kollision mit A5 (platforms.*.media_ids) per direktem SendMessage zwischen den Chips geloest |
| TPX-01 | T0 · Test-Infrastruktur | [#6](https://github.com/Cryptoom/trypost/pull/6) | `72dc5d5c` | 4553/4553 gruen, Koeder bestanden. Root Cause der urspruenglichen 306 Fehlschlaege: fehlende Passport-Keys (`passport:keys --force`), nicht der Port. Zwischenfall: `gh pr create` legte kurz einen PR gegen das oeffentliche Upstream trypostit/trypost an (PR #358, 1-2 Min sichtbar, sofort geschlossen), Regel-Fix als Backlog-Chip vorgemerkt (task_1fba1d69) |
| TPX-02 | A0 · Nachweis-Paket | [#7](https://github.com/Cryptoom/trypost/pull/7) MERGED | `45c08fb9` | (b) Postgres 16 in Produktion, medias.id/post_platforms.id beide uuid. (c) PostFactory sauber, ~15+ Testdateien nutzen erfundene Media-IDs. (d) Upstream-PR #287 KEIN Konflikt (nur numerische Constraints, keine Migration). (a) Echter Blocker gefunden, aufgeloest durch TPX-03 |
| TPX-03 | A0b · Media-ID-Design-Vertiefung | [#8](https://github.com/Cryptoom/trypost/pull/8) DO NOT MERGE, Referenz fuer A1 | - | **Root-Cause: Bug, nicht Feature.** Alle legitimen Schreibpfade (3 MCP-Attach-Tools, Web-Asset-Gallery, Unsplash/Giphy, AI-Regenerierung) liefern IDs ausschliesslich aus Server-Antworten, kein Client-Code erzeugt eigene IDs. **Empfehlung: `Rule::exists('medias','id')` in `PostMediaRules.php` ergaenzen**, workspace-gescoped gegen IDOR. Verifiziert: 11 neue Tests rot-vor-Fix/gruen-danach, voller `--filter=Post`-Lauf 967/967 gruen, 0 Regressionen. Nebenfund: ungefangenes Postgres-500 bei Nicht-UUID-id wird zu korrektem 422. IDOR bestaetigt+geschlossen (Test beweist Cross-Tenant-Ablehnung). Produktions-Check web02: 0/32 Posts betroffen, kein Backfill-Risiko fuer die harte FK in A1. Zwischenfall: erneut `gh pr create` ohne `--repo` traf kurz Upstream (PR #359), sofort geschlossen, bekanntes Muster (Regel-Fix von TPX-01 wirkt erst in neuer Session) |

## Discovered Backlog

- **Harness-Anomalie 2026-09-17**: ein unbenannter, nicht von der Orchestrator-Session
  gespawnter Sub-Agent (`a257737900c631e41`) meldete sich, behauptete als "tpx-05-a2" invoked
  worden zu sein, sass aber tatsaechlich im Worktree von `tpx-08-a5` (`agent-a7cb97531e7f4b99a`,
  Branch `claude/tpx-08-a5-vue-ui`). Kein Schaden (Worktree war sauber, `git status` leer),
  Agent wurde per SendMessage angewiesen stillzustehen. Vermutlich ein Harness-interner
  Duplikat-/Race-Effekt beim gleichzeitigen Spawnen mehrerer `Agent()`-Aufrufe in einer
  Nachricht. Kein Fix in unseren Regeln moeglich (Plattform-Ebene), aber als Beobachtung
  festgehalten falls es sich wiederholt.

## Code-Review-Status

- **PR #12 (A5, TPX-08)**: Code-Review PASS mit wichtigem Finding (Toggle-Logik-Bug, letztes
  Media-Item abwaehlen kippt auf "alle an" zurueck, vom Orchestrator selbst nachgerechnet und
  bestaetigt). Design-Review NEEDS-WORK (fehlender Card-Wrapper, Plattform-Zuordnung nicht
  erkennbar bei mehreren Plattform-Karten). Beide Nested-Review-Notifications kamen an den
  Orchestrator statt an den Chip (Routing-Anomalie bei verschachtelten Subagenten, dritter
  Beleg in dieser Welle). Chip wurde mit vollem Befund zurueckgeschickt, Fix + neue Review-
  Runde laeuft.

## Olli-Touchpoints

| Gate | Status | Notiz |
|---|---|---|
| YouTube-Scope-Gate (nach B0) | offen | Falls `youtube.force-ssl` fehlt: Re-Consent-Entscheidung noetig, Olli entscheidet ob/wann |
| Threads-App-Review (nach B0) | offen | Falls `threads_delete` nicht freigegeben: B2b parken, Olli macht Meta-App-Dashboard-Antrag |
| B4-Produktfrage | offen | Delete-von-Published im Web-UI freischalten oder nur Unpublish? Vor B4 fragen |
| U1-Freigabe | offen | Issue-Kommentar + PR-Text sieht Olli vor dem Absenden |
| Separater Upstream-PR fuer publishStory()-Fix (a30d83c2) | offen | Sofort machbar, unabhaengig von U1, Olli-OK vor Absenden |
| Upstream-PR #287 Ueberschneidung mit Feature A | zu pruefen in A0 | `gh pr view 287 --repo trypostit/trypost` |
| AGPL-Copyleft-Frage | AUSDRUECKLICH NICHT TEIL DIESER WELLE | Olli-Korrektur, nicht erwaehnen |
| Push-Gate-Scope | GEKLAERT 16.09.2026 | Branch-Push + PR autonom, Merge/Deploy bleiben Gates |

## Deploy-Status

Kein Deploy in dieser Welle bisher. Letzter bekannter Live-Stand: Commit `a30d83c2`
(Facebook-Story-Fix, 16.09.2026, laut vorherigem Chip deployed, hier nicht erneut verifiziert
bis zum ersten TPX-Deploy).
