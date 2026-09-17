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
> Handoff 2026-09-17: TPX-00 (urspruengliche Orchestrator-Session) offenbar nach Usage-Limit
> idle. Diese Session (trypost-fork-46) hat die Orchestrierung auf Olli-Anweisung uebernommen,
> lokalen main per Fast-Forward auf origin/main synchronisiert (war 12 Commits hinterher),
> Plan + Nachtgate erneut gelesen. **Welle A jetzt komplett gemergt** (A4 #13, A5 #12), inkl.
> eines echten Merge-Konflikts zwischen A4 und A5 in `UpdatePost.php` (unabhaengig doppelt
> gebaute `media_ids`-Sync-Logik), manuell aufgeloest und breit re-getestet. Naechster Schritt
> laut Plan: B0 (Nachweis-Paket, kein Code). Pausiert hier fuer Olli-Ruecksprache statt
> automatisch weiterzulaufen, da Welle-A-Ende ein sinnvoller Checkpoint ist.

Status-Symbole: ✓ done · 🚀 deployed · ⏳ in_progress · ❓ waiting · 🔄 pending · ⛔ blocked ·
❌ failed · 🔍 review · 📝 discovered

## Active Chips

| Chip | Paket | Status | Started | Worktree/Branch |
|---|---|---|---|---|
| TPX-10 | B1 · UnpublishPost + DeletePost | ⏳ in_progress | 2026-09-17 | claude/tpx-10-b1-unpublish-core |
| TPX-B1b | B1b · Instagram-Connect-Modal-Hinweis (NEU, Olli-Anlass 17.09.2026) | ⏳ in_progress | 2026-09-17 | claude/tpx-b1b-ig-modal-hint |

## Pending (Startreihenfolge)

| Chip | Paket | Status | Dependencies | Branch/PR | Plan/Real |
|---|---|---|---|---|---|
| TPX-10 | B1 · UnpublishPost + DeletePost | 🔄 pending, naechster Schritt | B0 ✓ | - | - |
| TPX-11 | B2a · Facebook/Instagram delete() | 🔄 pending, 2 offene Olli-Gates (Facebook "select developers"-Warnung, Instagram POST/DELETE-Doku-Widerspruch, beide per Smoke-Test klaerbar) | B0, B1, A3 | - | - |
| TPX-12 | B2b · Threads delete() | ⛔ geparkt (Plan-Vorgabe: B0 hat threads_delete-Permission NICHT bestaetigen koennen, Olli-Login noetig). Zusaetzlich Host-Diskrepanz gefunden, siehe Olli-Gates | B0, B1, A3, Threads-Gate | - | - |
| TPX-13 | B2c · LinkedIn delete() | 🔄 pending | B0, B1, A3 | - | - |
| TPX-14 | B2d · YouTube delete() | 🔄 pending | B0, B1, A3, YouTube-Scope-Gate | - | - |
| TPX-15 | B3 · MCP UnpublishPostTool | 🔄 pending | B1 | - | - |
| TPX-16 | B4 · Vue-UI + Web-Route | 🔄 pending | B1, B4-Produktfrage | - | - |
| TPX-17 | U1 · Upstream-PR-Vorbereitung | 🔄 pending | Welle B gemergt + deployed | - | - |

## Completed

| Chip | Paket | PR | Merge-Commit | Notiz |
|---|---|---|---|---|
| TPX-09 | B0 · Nachweis-Paket | kein PR (nur Recherche) | - | Facebook: `platform_post_id`-Format variiert je Content-Type (Feed=Komposit `{page}_{post}`, Video/Reel/Story=bare ID), DELETE braucht Komposit-Form. Instagram: Carousel-`platform_post_id`=Parent-Container bestaetigt, `instagram_manage_contents`-Scope FEHLT aktuell, Delete gilt laut Doku NUR fuer Facebook-Login-Instagram-Accounts (nicht fuer den direkten Instagram-Login-Typ). Threads: `threads_delete`-Scope fehlt aktuell, App-Review-Status nicht pruefbar (Olli-Gate). YouTube: `youtube.force-ssl` ist BEREITS im aktuell angeforderten Scope-Set, nur Bestandsaccounts vor diesem Scope ungeklaert. LinkedIn: URN aus `x-restli-id`-Header (nicht Body), DELETE laut Doku idempotent (204 bei Wiederholung), kein separater Delete-Scope dokumentiert. Bonus-Fund: `Platform::requiredPublishScopes()` + `failForMissingScopes()`-Gate existieren schon als Vorlage fuer ein analoges `requiredDeleteScopes()`. **7 Olli-Gates dokumentiert, siehe Abschnitt unten**, darunter eine potenziell kritische Threads-Host-Diskrepanz (`graph.threads.net` im Code vs. `graph.threads.com` in der aktuellen Meta-Doku), die auch den BESTEHENDEN Threads-Publish-Pfad betreffen koennte, nicht nur Delete |
| TPX-08 | A5 · Vue-UI | [#12](https://github.com/Cryptoom/trypost/pull/12) | `dfb7a055` | `MediaAssignmentGrid.vue` (neu) plus `ChannelConfigurator.vue`-Integration, per-Plattform Media-Toggle-Grid. Design-Runde 1 NEEDS-WORK (fehlender Card-Wrapper) + Code-Runde 1 Fund (Toggle-Bug: letztes Item abwaehlen kippte auf "alle an" zurueck), beides gefixt (`ad169c54`). Design-Runde 2 PASS. Code-Runde 2 NEEDS-WORK (fehlender Regressionstest fuer den Toggle-Fix), Test ergaenzt (`tests/Browser/MediaAssignmentGridTest.php`, `f42c0546`), Code-Runde 3 PASS inkl. Mutationstest (Guard deaktiviert -> Test schlaegt korrekt fehl, zurueckgesetzt -> gruen). Echter Merge-Konflikt gegen A4 in `UpdatePost.php` (beide PRs implementierten unabhaengig dieselbe `media_ids`-Sync-Logik), manuell aufgeloest (A5s Variante ohne redundanten Re-Fetch von `$postPlatform` behalten), 1003/1003 Post-Sweep + Browser-Test danach gruen. Autonom gemergt (Nachtmodus, Handoff-Session trypost-fork-46) |
| TPX-07 | A4 · MCP-Tool-Parameter | [#13](https://github.com/Cryptoom/trypost/pull/13) | `f3214f59` | `post_platform_ids` an 3 Attach-Tools, `platforms.*.media_ids` mit Sync-Semantik an `UpdatePostTool` (`Arr::has()`-Unterscheidung weggelassen/leer/gefuellt). IDOR-Schutz mehrfach getestet. Runde 1 PASS mit "Wichtig"-Fund (post_id-Lookup vor Validierung in 3 Attach-Tools, Crash-Risiko bei malformed/Array-post_id), gefixt (`60627d58`), Runde 2 PASS. Autonom gemergt (Nachtmodus, Handoff-Session trypost-fork-46). Nebenfund (nicht gefixt, vorbestehend, nicht Teil dieser PR): `UpdatePostTool.php` hat denselben Bug, als Backlog-Chip TPXB-01 vorgemerkt |
| TPX-04 | A1 · Migration + Model + Media-ID-Fix | [#9](https://github.com/Cryptoom/trypost/pull/9) | `877a2a9c` | `media_post_platform`-Pivot (uuid), `MediaPostPlatform`-Custom-Pivot-Model (`HasUuids`), `PostPlatform::media()`+`scopedMediaItems()`. TPX-03-Fix workspace-gescoped ueber `medias.mediable_type`/`mediable_id` (polymorph, KEINE literale workspace_id-Spalte, Abweichung vom Briefing-Wortlaut zugunsten der verifizierten TPX-03-Implementierung). 975/975 Post-Tests, 367/367 Mcp-Tests, 2 Review-Runden PASS, autonom gemergt (Nachtmodus) |
| TPX-06 | A3 · Publisher-Rollout | [#10](https://github.com/Cryptoom/trypost/pull/10) | `f411d274` | 12 Dateien geaendert (Plan-Liste nannte nur 9, `DiscordPublisher.php`+`TelegramPublisher.php` fehlten in der Plan-Liste, per Grep gefunden und mitgefixt), 16 Call-Site-Ersetzungen. `mediaSnapshot()` bewusst unveraendert. 420/420 Publisher-Tests gruen, 1 Review-Runde PASS, autonom gemergt |
| TPX-05 | A2 · Validierungs-Umbau | [#11](https://github.com/Cryptoom/trypost/pull/11) | `7b73cf56` | `ContentTypeCompatibleWithMedia::entriesForUpdate()` loest pro Plattform eigene Media-Liste auf (Request-Media > scopedMediaItems() > volle Post-Liste). Randfall (Media-Pflicht, Liste leer) bleibt korrekt ein Fehler. Web-Aufrufstelle auf `after()`-Validator umgestellt (schliesst nebenbei eine API-Luecke). Lazy-Loading-Bug in scopedMediaItems() unter shouldBeStrict() nebenbei gefixt. 849/850 breiter Sweep gruen, 2 Review-Runden PASS. Koordinierte Kollision mit A5 (platforms.*.media_ids) per direktem SendMessage zwischen den Chips geloest |
| TPX-01 | T0 · Test-Infrastruktur | [#6](https://github.com/Cryptoom/trypost/pull/6) | `72dc5d5c` | 4553/4553 gruen, Koeder bestanden. Root Cause der urspruenglichen 306 Fehlschlaege: fehlende Passport-Keys (`passport:keys --force`), nicht der Port. Zwischenfall: `gh pr create` legte kurz einen PR gegen das oeffentliche Upstream trypostit/trypost an (PR #358, 1-2 Min sichtbar, sofort geschlossen), Regel-Fix als Backlog-Chip vorgemerkt (task_1fba1d69) |
| TPX-02 | A0 · Nachweis-Paket | [#7](https://github.com/Cryptoom/trypost/pull/7) MERGED | `45c08fb9` | (b) Postgres 16 in Produktion, medias.id/post_platforms.id beide uuid. (c) PostFactory sauber, ~15+ Testdateien nutzen erfundene Media-IDs. (d) Upstream-PR #287 KEIN Konflikt (nur numerische Constraints, keine Migration). (a) Echter Blocker gefunden, aufgeloest durch TPX-03 |
| TPX-03 | A0b · Media-ID-Design-Vertiefung | [#8](https://github.com/Cryptoom/trypost/pull/8) DO NOT MERGE, Referenz fuer A1 | - | **Root-Cause: Bug, nicht Feature.** Alle legitimen Schreibpfade (3 MCP-Attach-Tools, Web-Asset-Gallery, Unsplash/Giphy, AI-Regenerierung) liefern IDs ausschliesslich aus Server-Antworten, kein Client-Code erzeugt eigene IDs. **Empfehlung: `Rule::exists('medias','id')` in `PostMediaRules.php` ergaenzen**, workspace-gescoped gegen IDOR. Verifiziert: 11 neue Tests rot-vor-Fix/gruen-danach, voller `--filter=Post`-Lauf 967/967 gruen, 0 Regressionen. Nebenfund: ungefangenes Postgres-500 bei Nicht-UUID-id wird zu korrektem 422. IDOR bestaetigt+geschlossen (Test beweist Cross-Tenant-Ablehnung). Produktions-Check web02: 0/32 Posts betroffen, kein Backfill-Risiko fuer die harte FK in A1. Zwischenfall: erneut `gh pr create` ohne `--repo` traf kurz Upstream (PR #359), sofort geschlossen, bekanntes Muster (Regel-Fix von TPX-01 wirkt erst in neuer Session) |

## Discovered Backlog

- **TPXB-01 (spawn_task-Chip, task_fe146be6, noch nicht gestartet)**: `UpdatePostTool.php` hat
  denselben Bug wie die drei A4-Attach-Tools vor ihrem Fix (`post_id`-Lookup VOR der Validierung,
  malformed/Array-`post_id` kann Exception statt sauberer 422 ausloesen). Verifiziert: NICHT
  Teil der A4-PR-Aenderung, vorbestehend (mind. seit A2-Merge #11). Bewusst nicht in A4
  mitgefixt (Scope-Disziplin), eigener Backlog-Chip mit eigenem PR vorgemerkt.
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
| YouTube-Scope-Gate (nach B0) | GROSSTEILS GEKLAERT | B0-Fund: `youtube.force-ssl` wird bereits im AKTUELLEN Connect-Flow angefordert (kein Code-Fix noetig fuer neue Accounts). Offen bleibt nur: haben ALTE Bestandsaccounts (vor Hinzufuegen dieses Scopes) ihn tatsaechlich, DB-Check noetig, bewusst nicht in B0 gemacht (Produktions-DB, kein Read-only-Recherche-Scope) |
| Threads-App-Review (nach B0) | offen, B2b geparkt | `threads_delete`-Scope fehlt im Code (muss ergaenzt werden), App-Review-Freigabestatus im Meta Dashboard nicht per Code pruefbar, Olli-Login noetig |
| **NEU: Threads-Host-Diskrepanz** | offen, potenziell dringend | B0-Fund: Code nutzt `graph.threads.net` (`config/trypost.php`), die AKTUELLE Meta-Doku (mehrfach konsistent gefetcht 17.09.2026) zeigt durchgaengig `graph.threads.com`. Koennte den BESTEHENDEN Threads-Publish-Pfad betreffen, nicht nur das neue Delete-Feature. Braucht einen echten Testcall zur Klaerung, nicht nur Doku-Lesen |
| Facebook "nur ausgewaehlte Entwickler"-Warnung (B0) | offen, vor B2a | Wortlaut auf der offiziellen Page-Post-Delete-Doku: "Only select developers can perform this operation using the API." Unklar ob Boilerplate oder echte Einschraenkung, Smoke-Test vor B2a-Aufwand klaert es |
| Instagram Delete gilt nur fuer Facebook-Login-Accounts (B0) | GEKLAERT 17.09.2026 | Olli-Entscheid: sauberer Unsupported-Pfad fuer `Platform::Instagram` (direct login) analog TikTok, `Platform::InstagramFacebook` bekommt echtes delete(). Siehe Plan OLLI-ENTSCHEIDE Runde 4 Punkt 9. Betrifft B2a/B3 |
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
