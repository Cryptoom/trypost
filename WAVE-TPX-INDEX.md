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
> Olli-Freigabe 17.09.2026 (weitergehend): "für Oliver legst du zweiten MCP an selbst und
> prüfst ohne [zu fragen], darfst weitermachen und alles fertigbauen, testen erst wenn fertig
> und dann via buttons prüfen nicht nur via code". Konsequenz: Welle B (B2a, B2c, B2d, B3, B4)
> wird jetzt am Stueck gebaut + code-reviewed, OHNE zwischendurch nachzufragen. Live-Smoke-Tests
> und der finale Merge von B2a/c/d bleiben aber an die Bedingung "erst wenn fertig, dann ueber
> echte Buttons in der UI pruefen" gebunden, nicht per Code/API allein. B2b (Threads) bleibt
> geparkt (echte externe Blockade: Meta-App-Review-Status, nicht per Code pruefbar). Fuer
> Zwischentests: Personal-API-Key fuer den Workspace "Oliver Albrecht" per `artisan tinker`
> erstellt (`CreateApiKey`-Action, identisch zum MCP-Tool-Pfad), lokal in `~/.claude/.env` als
> `TRYPOST_OLIVER_WORKSPACE_API_KEY` hinterlegt, NICHT committed. Das ist ein Personal-Access-
> Token fuer die REST-API, KEIN vollwertiger Claude-MCP-Connector: ein echter MCP-Connector
> braeuchte den OAuth-Consent-Flow (`Mcp::oauthRoutes()`), der einen einmaligen Klick von Olli
> in seiner eingeloggten Browser-Session braucht (Chrome-MCP war in dieser Session nicht
> erreichbar, "Claude in Chrome" Extension nicht verbunden). Bei Bedarf spaeter nachholen.
> Handoff 2026-09-17: TPX-00 (urspruengliche Orchestrator-Session) offenbar nach Usage-Limit
> idle. Diese Session (trypost-fork-46) hat die Orchestrierung auf Olli-Anweisung uebernommen,
> lokalen main per Fast-Forward auf origin/main synchronisiert (war 12 Commits hinterher),
> Plan + Nachtgate erneut gelesen. **Welle A jetzt komplett gemergt** (A4 #13, A5 #12), inkl.
> eines echten Merge-Konflikts zwischen A4 und A5 in `UpdatePost.php` (unabhaengig doppelt
> gebaute `media_ids`-Sync-Logik), manuell aufgeloest und breit re-getestet.
> **Welle B jetzt ebenfalls komplett gemergt UND deployed** (B2a #20, B2c #17, B2d #18, B4 #21,
> in dieser Reihenfolge, siehe Merge-Reihenfolge-Warnung oben). Post-Merge-Sweep zeigte zunaechst
> 126 Fehlschlaege, beide Ursachen gefunden und behoben (Commit `b484af8b`, siehe Completed-
> Tabelle): (1) 124 davon waren eine Umgebungsluecke dieser lokalen Testinstanz, fehlende
> Passport-OAuth-Keys (`storage/oauth-*.key`, gitignored, `passport:keys --force` behebt es),
> KEINE echte Regression. (2) 2 echte Fehlschlaege in `UnpublishPostToolTest.php`: die Fixtures
> nutzten LinkedIn als Beispiel-Plattform fuer "unsupported", das stimmte als B3 gebaut wurde
> (0 Publisher hatten delete()), ist aber nach B2c falsch (LinkedIn hat jetzt echtes delete()
> und versucht einen echten API-Call statt "unsupported" zu liefern). Fix: beide Tests auf
> TikTok umgestellt (bleibt genuin unsupported, kein delete/unpublish-Endpoint), analog zum
> bereits etablierten Muster im Action-Level-Test. Zusaetzlicher, unabhaengiger Fund beim
> Voll-Sweep (kein Post-Filter): `MediaPostPlatform`-Model fehlte im Morph-Map
> (`AppServiceProvider::configureMorphMap()`), ergaenzt. Voller Sweep danach 4655 passed, 2
> skipped, 0 failed. Deploy auf web02 durchgefuehrt und verifiziert, siehe Deploy-Status.

Status-Symbole: ✓ done · 🚀 deployed · ⏳ in_progress · ❓ waiting · 🔄 pending · ⛔ blocked ·
❌ failed · 🔍 review · 📝 discovered

## Active Chips

| Chip | Paket | Status | Started | Worktree/Branch |
|---|---|---|---|---|
(keine · Welle B komplett gemergt und deployed, siehe Completed-Tabelle und Deploy-Status)

Alle 5 laufen parallel, disjunkte Dateien laut Konflikt-Matrix. TPX-11 (B2a) ist der einzige der
`UnpublishPost.php` anfasst (Pflicht-Fix aus dem B1-Review, `published_at`-Bug), die anderen vier
ruehren diese Datei nicht an. Kein Merge von B2a/B2c/B2d ohne vorherigen Live-Smoke-Test
(Plan-Pflicht), B3/B4 warten auf Review + Olli-Buttons-Test.

**WICHTIG (B2d-Review-Fund, gilt fuer B2a/B2c/B2d gleichermassen)**: `UnpublishPost.php`s
Dispatch (`method_exists($publisher, 'delete')`) ist automatisch. Der MERGE eines B2x-PRs
selbst, nicht erst ein spaeterer Schritt, aktiviert sofort einen bestehenden Live-Pfad
(Web-UI-Delete, REST-API-Delete, MCP-`DeletePostTool`/`UnpublishPostTool`), ueber den ab dem
Merge-Zeitpunkt echte Plattform-Inhalte geloescht werden koennen. Live-Smoke-Test VOR Merge ist
darum keine Formalitaet, sondern verhindert dass der allererste echte Delete-Versuch
unbeaufsichtigt ueber einen bereits scharfen Produktions-Pfad laeuft.

**B2b (Threads) GESTRICHEN 17.09.2026 (Olli, explizit)**: "B2b (Threads) bleibt geparkt, machen
wir derzeit nicht, nutzt keiner." Nicht mehr nur "geparkt bis Meta-App-Review", sondern bewusst
ausserhalb dieser Welle. Kein Chip dafuer, kein Olli-Gate mehr offen dafuer.

**TPX-16 (B4, Web-UI) fertig**: PR [#21](https://github.com/Cryptoom/trypost/pull/21).
`POST /posts/{post}/unpublish` + `PostController::unpublish()` (3 Outcome-Flashes success/
partial/unsupported), neue Unpublish-Aktion in `Index.vue` neben Delete, `ConfirmDeleteModal`
um `testid`-Prop erweitert (2 Instanzen pro Seite). `DELETE_BLOCKED_STATUSES` auf `[Publishing]`
reduziert (Published/PartiallyPublished entsperrt, deckt sich mit dem API-`destroy()`-Verhalten).
Mixed-Platform-Loesung: Unpublish-Aktion nur disabled wenn ALLE Plattformen unsupported sind
(TikTok, Instagram-direct-login), bei gemischten Posts bleibt sie aktiv, Partial-Outcome per
Flash. Pro-Plattform-Unpublish (`Edit.vue`) bewusst als Folge-Arbeit ausgeklammert. **Nebenfund**:
fehlende `REVERB_*`/`VITE_REVERB_*`-Env-Vars liessen JEDE Posts-Seite beim Vue-`setup()` lautlos
abbrechen (kein console.error), brach auch den bereits gemergten `MediaAssignmentGridTest`
(unberuehrt vom Chip), bestaetigt als reine Infra-Luecke, nicht Regression. Gefixt in `.env`/
`.env.testing`. Browser-Test `PostUnpublishTest.php` (2 Szenarien) gruen, LocalizationParityTest
18/18, breiter Post-Sweep gruen (bekannte Container-Flakiness isoliert nachgewiesen).
**Design-Review PASS**: Unpublish nutzt bewusst `default`-Variant (nicht `destructive`, weil
reversibel), konsistent zu Delete. Disabled-State visuell erkennbar (`data-[disabled]:opacity-50`),
Tooltip-Ton konsistent zu B1b. 16/16 Locales stichprobenartig gegengelesen, idiomatisch nicht
maschinell kopiert. Kein Browser-Screenshot moeglich (PHP-Alias-Falle in dieser Review-Session),
Urteil auf Code-/Tailwind-Analyse gestuetzt.
**Code-Review PASS**, verifiziert (Autorisierung, `DELETE_BLOCKED_STATUSES`-Reduktion stimmt mit
`Api\PostController::destroy()` ueberein, Mixed-Platform-Logik korrekt, IDOR-Test vorhanden, alle
Tests selbst gruen bestaetigt). **Wichtiger Merge-Reihenfolge-Fund**: `Index.vue` nutzt eine
EIGENE statische Liste (`UNPUBLISH_UNSUPPORTED_PLATFORMS = ['tiktok','instagram']`), nicht dieselbe
Erkennung wie Backend `UnpublishPost::resolveDeletePublisher()`. Solange B4 OHNE B2a/c/d live geht,
zeigt die UI fuer JEDEN Post mit Facebook/LinkedIn/YouTube/etc. den Unpublish-Button als aktiviert,
obwohl das Backend noch fuer jede dieser Plattformen `unsupported` liefert (0 Publisher haben
aktuell `delete()`). Kein Datenverlust, aber irrefuehrend (Button verspricht eine Faehigkeit die
noch fehlt). **Empfehlung: B4 NICHT vor B2a/c/d live schalten**, sondern zusammen oder danach.
Zwei kleinere, aktuell unerreichbare Nicht-Blocker: `unpublish()` mapped `failed`-only faelschlich
auf die `unsupported`-Meldung (wird erst relevant sobald B2a/c/d live sind); kein server-seitiger
Status-Guard fuer `unpublish()` bei `Publishing` (nur client-seitig, schmales Race-Fenster).

**TPX-11 (B2a, Facebook/Instagram) fertig**: PR [#20](https://github.com/Cryptoom/trypost/pull/20).
`FacebookPublisher::delete()` generisches Graph-Node-Delete (funktioniert fuer Feed-Komposit-ID
und bare Video-ID ohne Content-Type-Verzweigung). `InstagramPublisher::delete()` loescht die
gespeicherte (bei Carousels: Parent-)Media-ID. Instagram/InstagramFacebook-Unterscheidung geloest:
`UnpublishPost::resolveDeletePublisher()` faengt `Platform::Instagram` (direkter Login) explizit
VOR dem `method_exists()`-Check ab, liefert immer unsupported (analog TikTok). Neues
`Platform::requiredDeleteScopes()` + Scope-Gate in `UnpublishPost::execute()`, fehlender Scope
endet als `failed` mit Reconnect-Hinweis statt rohem API-Fehler, `instagram_manage_contents` zum
Connect-Flow ergaenzt (fehlte laut B0). B1-Pflicht-Nacharbeit (`published_at`-Bug) gefixt PLUS
eigener Fund: `LazyLoadingViolationException` beim Scope-Check (eager-loading `socialAccount`
nachgezogen). 122 neue Tests + 2145-Test-Sweep gruen (isolierter Wegwerf-Container Port 5555
wegen Schema-Drift im geteilten Testcontainer durch parallele Chips). Zwei offene B0-Olli-Gates
(Facebook-Warnung, Instagram-Doku-Widerspruch) explizit im PR-Body als Merge-Blocker bis zum
Live-Smoke-Test benannt. **Review-Runde 1 PASS, keine Funde.** Kritischster Punkt (Instagram-
Direct-Login darf nie loeschbar sein) per aktivem Spy-Test bewiesen (`calls === 0`), nicht nur
Kommentar. Runde 2 noch offen, kein Merge bis Live-Smoke-Test.

**TPX-14 (B2d, YouTube) fertig**: PR [#18](https://github.com/Cryptoom/trypost/pull/18),
`YouTubePublisher::delete()`, `videos.delete` mit bare Video-ID, `videoNotFound` (404) als
idempotenter Erfolg behandelt (gegen offizielle Doku verifiziert), Scope bereits ausreichend
(kein Connect-Flow-Fix noetig). Echter Testbarkeits-Fund: `Http::fake()` faengt Googles
eigenen Guzzle-Transport NICHT ab, alle 8 bestehenden Tests haben das nie wirklich erreicht.
Chip hat eine minimale, produktionsneutrale Testbarkeits-Naht ergaenzt (`createGoogleClient()`
nutzt einen optional gebundenen `GuzzleHttp\ClientInterface`), 12/12 Tests jetzt echt end-to-end.
**Review-Runde 1 PASS**, zwei nicht-blockierende Wichtig-Punkte (kein `catch(\Throwable)` in
`delete()` anders als `publishShort()`, dadurch kein Server-Log bei unerwarteten Netzwerkfehlern;
Docblock behauptet faelschlich "teuerste Data-API-Operation" fuer die 50 Quota-Units, obwohl
`videos.insert` in derselben Datei 1600 kostet). **Wichtiger Fund des Reviewers, gilt fuer JEDEN
B2a/c/d-Merge**: `UnpublishPost::resolveDeletePublisher()` erkennt `delete()` automatisch per
`method_exists()`. Der Merge selbst aktiviert damit sofort einen bestehenden Live-Pfad (Web/API/
MCP-Delete-Endpoints), nicht nur eine bisher ungenutzte Methode, echte YouTube-Videos koennen ab
dem Merge-Zeitpunkt geloescht werden. Macht den Live-Smoke-Test-VOR-Merge (Plan-Pflicht) noch
wichtiger als ohnehin schon. Runde 2 noch offen, kein Merge bis Smoke-Test.

**TPX-15 (B3) gemergt**: siehe Completed-Tabelle.

**TPX-13 (B2c, LinkedIn) fertig, Runde 1 PASS**: PR [#17](https://github.com/Cryptoom/trypost/pull/17),
`AbstractLinkedInPublisher::delete()`, deckt beide Unterklassen ab, Idempotenz (204 bei
Wiederholung) korrekt behandelt. 60/60 + 44/44 Sanity-Tests gruen. Review-Runde 1 PASS, ein
nicht-blockierender "Wichtig"-Punkt (kein Retry-Pfad bei TokenExpiredException, im Gegensatz
zu `publish()`, aber durch Idempotenz abgefedert) plus ein Reminder: `UnpublishPost.php`s
Doc-Kommentar ("today no publisher has delete()") ist nach diesem Merge veraltet, B2a (der
einzige der diese Datei anfasst) sollte das beim eigenen Fix mitnehmen. **Kollisions-Warnung
vom Chip selbst**: `tests/Feature/Actions/Post/UnpublishPostTest.php` wurde angefasst (4
Szenarien LinkedIn -> TikTok als "unsupported"-Beispiel). B2a (TPX-11) aendert dieselbe
Testdatei fuer seine eigenen Instagram/Facebook-Szenarien, echtes Merge-Konflikt-Risiko dort
(analog A4/A5s `UpdatePost.php`-Konflikt), beim Mergen beider PRs beachten. Runde 2 + Live-
Smoke-Test noch offen, kein Merge bis dahin (Plan-Pflicht fuer B2a-d).

## Pending (Startreihenfolge)

**Pflicht-Nacharbeit VOR B2a-d** (aus B1-Review, siehe Completed-Tabelle): (1) `UnpublishPost::updatePostStatus()`
nutzt bei Teil-Erfolg `Post::markAsPartiallyPublished()` zweckentfremdet, das setzt `published_at`
faelschlich auf den Unpublish-Zeitpunkt statt ihn unangetastet zu lassen. (2) `method_exists($publisher,
'delete')` prueft keine Sichtbarkeit, sollte bei neuen Implementierungen entweder `delete()` konsequent
`public` halten oder per `ReflectionMethod::isPublic()` absichern. Beides aktuell folgenlos (Erfolgspfad ist
noch dead code, 0 Publisher haben `delete()`), wird aber SCHARF sobald der erste B2a-d-Chip eine echte
`delete()`-Methode liefert. Der jeweilige B2a-d-Chip MUSS Punkt 1 mitfixen (eine Zeile), bevor sein PR
gemergt wird.

| Chip | Paket | Status | Dependencies | Branch/PR | Plan/Real |
|---|---|---|---|---|---|
| TPX-12 | B2b · Threads delete() | ❌ GESTRICHEN 17.09.2026 (Olli: "machen wir derzeit nicht, nutzt keiner"), kein Chip mehr vorgesehen | - | - | - |
| TPX-17 | U1 · Upstream-PR-Vorbereitung | ❌ GESTRICHEN 17.09.2026 (Olli: "nein wir wollen nicht helfen!") | - | - | Kein Issue-Kommentar, kein PR-Angebot an trypostit/trypost#228. Separater `publishStory()`-Fix (echter Bug, kein Feature-Beitrag) bleibt unberuehrt: [trypostit/trypost#360](https://github.com/trypostit/trypost/pull/360) |

## Completed

| Chip | Paket | PR | Merge-Commit | Notiz |
|---|---|---|---|---|
| - | Post-Merge-Fix (Morph-Map + stale Test-Fixtures) | kein PR, direkt auf main (Olli-Freigabe) | `b484af8b` | `MediaPostPlatform` fehlte im Morph-Map, ergaenzt. `UnpublishPostToolTest.php` nutzte LinkedIn als "unsupported"-Beispiel, seit B2c falsch (LinkedIn hat jetzt delete()), auf TikTok umgestellt. Voller Sweep danach 4655 passed/2 skipped/0 failed |
| TPX-16 | B4 · Vue-UI + Web-Route | [#21](https://github.com/Cryptoom/trypost/pull/21) | `ccb75401` | Siehe volle Notiz oben (Active-Chips-Sektion vor dem Merge). Gemergt in der Reihenfolge B2a→B2c→B2d→B4 wie vom Reviewer empfohlen, damit der Unpublish-Button in `Index.vue` nie eine Faehigkeit vorspiegelt die das Backend noch nicht hat |
| TPX-14 | B2d · YouTube delete() | [#18](https://github.com/Cryptoom/trypost/pull/18) | `a70f0b45` | Siehe volle Notiz oben. `YouTubePublisher::delete()`, `videoNotFound` idempotent, Testbarkeits-Naht fuer Googles Guzzle-Transport |
| TPX-13 | B2c · LinkedIn delete() | [#17](https://github.com/Cryptoom/trypost/pull/17) | `faa4cb31` | Siehe volle Notiz oben. `AbstractLinkedInPublisher::delete()`, deckt Profile- und Page-Unterklasse ab |
| TPX-11 | B2a · Facebook/Instagram delete() | [#20](https://github.com/Cryptoom/trypost/pull/20) | `27420c66` | Siehe volle Notiz oben. `FacebookPublisher::delete()` + `InstagramPublisher::delete()`, `Platform::Instagram` (direct login) explizit unsupported gehalten, per Spy-Test bewiesen |
| TPX-15 | B3 · MCP UnpublishPostTool | [#19](https://github.com/Cryptoom/trypost/pull/19) | `335fab77` | Neues `UnpublishPostTool.php`, `post_id` isoliert vorab validiert (A4/TPXB-01-Muster), `post_platform_ids`-IDOR-Schutz per `Rule::exists(...)->where('post_id', ...)` mit Test bestaetigt, Autorisierung ueber `update`. `unsupported_platforms` explizit in der Antwort. 9/9 Tests gruen, Review PASS ohne Funde. Keine Live-API-Abhaengigkeit (ruft nur B1s bereits unsupported Dispatch auf), darum 1 Review-Runde ausreichend (Plan-Vorgabe), autonom gemergt |
| TPX-10 | B1 · UnpublishPost + DeletePost | [#16](https://github.com/Cryptoom/trypost/pull/16) | `e81bc05c` | Neue `UnpublishPost.php`, `Post::markAsUnpublished()`, `PostPlatform::markAsUnpublished()`, `DeletePost::execute()` ruft Unpublish jetzt als ersten Schritt (best-effort). Bewusste Design-Abweichung vom Plan: `method_exists($publisher, 'delete')` statt hartem `match`, damit B2a-d ihre `delete()`-Methoden ergaenzen koennen ohne `UnpublishPost.php` nochmal anzufassen. TikTok-Negativtest auf beiden Ebenen (Unpublish + Delete). Review PASS mit 2 "Wichtig"-Funden (siehe Pflicht-Nacharbeit-Hinweis oben), aktuell folgenlos da Erfolgspfad noch dead code (0 Publisher haben delete()). 36 neue Tests, 1015/1015 breiter Post-Sweep gruen (nach migrate:fresh, erster Lauf zeigte Schema-Drift im geteilten Test-Container, Infra-Rauschen). Olli hat den Nachtmodus-Gate-Stopp fuer diesen Merge explizit aufgehoben, autonom gemergt |
| TPXB-01 | UpdatePostTool post_id-Validierungsreihenfolge | [#14](https://github.com/Cryptoom/trypost/pull/14) | `07ca0469` | Vom Olli-gestarteten Backlog-Chip (task_fe146be6) gebaut, identisches Fix-Muster wie A4 (`60627d58`), diesmal auf `UpdatePostTool.php`. Review verifizierte per Revert-Test, dass die 2 neuen Regressionstests den Bug wirklich fangen (malformed post_id wirft ohne Fix eine echte QueryException). 308/308 Mcp-Tests gruen. Olli hat den Nachtmodus-Gate-Stopp fuer diesen Merge explizit aufgehoben ("darfst wenn es sauber ist selbst mergen"), autonom gemergt |
| TPX-B1b | B1b · Instagram-Connect-Modal-Hinweis | [#15](https://github.com/Cryptoom/trypost/pull/15) | `468484b9` | NEU, Olli-Anlass 17.09.2026. Amber-Hinweis unter dem direkten Instagram-Login-Button, 16 Locales uebersetzt. Chip behauptete faelschlich "kein PHP 8.4+ auf der Maschine" (Zsh-Alias-Falle, `unalias php` + fehlende `.env` nie geloest), Orchestrator hat LocalizationParityTest (18/18) + `npm run build` selbst nachgeholt und gruen bekommen. Design-Review PASS (Amber-Konvention exakt getroffen, 16/16 Locales verifiziert, Uebersetzungen stichprobenartig gegengelesen). Autonom gemergt |
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

- **TPXB-01**: siehe Completed-Tabelle, PR #14 gemergt (Olli hat den Nachtmodus-Gate-Stopp fuer diesen konkreten Merge explizit aufgehoben).
- **Haertungs-Luecke, live gefunden 17.09.2026 (adversarialer Scope-Re-Check)**: `Platform::
  requiredDeleteScopes()` hat KEINEN Eintrag fuer YouTube (`default => []`), obwohl
  `videos.delete` real die Scope `youtube.force-ssl` verlangt. Oliver Albrechts Account hat sie
  (Server-Check bestaetigt), also aktuell kein Live-Problem, aber ein Account ohne diese Scope
  (z.B. verbunden bevor `force-ssl` zum Connect-Flow hinzugefuegt wurde, analog zum
  Instagram-Fall) wuerde NICHT die freundliche "Missing permissions, bitte neu verbinden"
  Meldung bekommen, sondern eine rohe Google-API-Fehlermeldung durchgereicht. Fix-Vorschlag:
  `self::YouTube => ['https://www.googleapis.com/auth/youtube.force-ssl']` ergaenzen, analog zu
  Facebook/Instagram. Kein Blocker fuer den aktuellen Live-Betrieb, aber ein sauberer,
  risikoarmer Folge-PR.
- **Bug, live bestaetigt 17.09.2026 (Live-Smoke-Test)**: `PostController::unpublish()` zeigt bei
  einem Doppel-Klick auf "Unpublish" (2. Klick nach bereits erfolgreichem 1. Klick, 0 verbleibende
  Kandidaten-Rows) die irrefuehrende Meldung "This post can't be unpublished automatically. Remove
  it manually on the platform(s) it was published to.", obwohl der Post laengst korrekt entfernt
  wurde. Ursache: `if ($result['unpublished'] === [])` behandelt "nichts mehr zu tun" identisch zu
  "wirklich unsupported". Deckt sich mit dem bereits im B4-Review dokumentierten Nicht-Blocker
  ("`unpublish()` mapped `failed`-only faelschlich auf die `unsupported`-Meldung"), jetzt zusaetzlich
  fuer den Fall einer leeren Kandidatenliste bestaetigt. Fix-Vorschlag: `unsupported`- und
  `failed`-Faelle sowie "nichts zu tun" als drei separate Flash-Zweige fuehren, nicht ueber
  `unpublished === []` zusammenfassen. Kein Datenverlust, rein irrefuehrende UI-Meldung.
- **Facebook-Story Foto-zu-Video-Auto-Konvertierung (Olli-Wunsch 17.09.2026, ueber Peer-Session
  playcraft-toys-bf relayed)**: `FacebookPublisher::publishStory()` lehnt reine Fotos hart ab
  (`'Facebook Stories require a video file.'`). PlayCraft umgeht das aktuell extern
  (`bin/image-to-story-video.sh`, ffmpeg, Standbild + stille AAC-Spur, 15s, Format das
  Facebooks `video_stories`-Endpoint akzeptiert). Wunsch: TryPost macht das serverseitig
  automatisch (erkennt `content_type=facebook_story` + Bild-Media, konvertiert intern nach
  demselben Muster), macht den externen Workaround fuer jeden Kunden mit reinen Foto-Assets
  ueberfluessig. Kein Zeitdruck, explizit als Backlog fuer eine SPAETERE Welle vorgemerkt, NICHT
  Teil von Feature A/B dieser Welle (Media-pro-Plattform bzw. Delete/Unpublish, kein
  Medien-Transkodierungs-Bezug).
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
| U1-Freigabe | GESTRICHEN 17.09.2026 | Olli explizit: "nein wir wollen nicht helfen!" Kein Upstream-Beitrag |
| Separater Upstream-PR fuer publishStory()-Fix (a30d83c2) | offen | Sofort machbar, unabhaengig von U1, Olli-OK vor Absenden |
| Upstream-PR #287 Ueberschneidung mit Feature A | zu pruefen in A0 | `gh pr view 287 --repo trypostit/trypost` |
| AGPL-Copyleft-Frage | AUSDRUECKLICH NICHT TEIL DIESER WELLE | Olli-Korrektur, nicht erwaehnen |
| Push-Gate-Scope | GEKLAERT 16.09.2026 | Branch-Push + PR autonom, Merge/Deploy bleiben Gates |
| `instagram_manage_contents`-Scope, App-Review noetig | ERLEDIGT 17.09.2026 | **Korrektur:** urspruenglich hier faelschlich als "neuer kritischer Fund" gefuehrt, ist aber Teil eines seit 24.08.2026 bekannten, dokumentierten Blockers (Standard-Access-Problem fuer ALLE Meta-Permissions dieser App, Consent bricht fuer Kunden ohne App-Rolle ab; App Review fuer 8 andere Permissions am 10.09.2026 abgelehnt, Screencast-Neuaufnahme seit 11.09.2026 vorbereitet, noch nicht eingereicht). Voller Kontext: Vault `50-Personal/projects/trypost-social-accounts-inventar-pointer.md`. `instagram_manage_contents` (B2a-Delete-Scope) war bisher NIE zur App-Review hinzugefuegt (anders als die 8 anderen), am 17.09.2026 per Klick ergaenzt (Status "0 / Bereit zum Testen"). Reconnect erfolgreich (Olli loeste die Business-Manager-Picker-Ambiguitaet selbst: bestehende TryPost-Verbindung zuerst trennen, dann Instagram im frischen OAuth-Flow auswaehlbar), neuer `SocialAccount` traegt `instagram_manage_contents` im `scopes`-Array (per tinker verifiziert). **Finaler End-to-End-Test bestanden**: echter Instagram-Feed-Testpost via API erstellt+published (`instagram.com/p/DdZtfj5FNd7/`), danach ueber den ECHTEN UI-"Unpublish"-Button (Posts-Liste, "..."-Menue, Bestaetigungsdialog) geloescht. Post verschwand aus der Published-Liste, `PostPlatform`-Status zurueck auf `pending`, `platform_post_id` genullt, kein `error_message`, kein "Instagram delete failed" im Server-Log. Der Instagram-Delete-Pfad (B2a) ist damit vollstaendig verifiziert, nicht nur der Code-Pfad. Offen bleibt nur die Meta-App-Review-Resubmission (Video A/B Screencast) fuer den Standard-Access-Blocker, das ist Ollis eigene Aufgabe, kein TPX-Code-Thema mehr. |

## Deploy-Status

Kein Deploy in dieser Welle bisher. Letzter bekannter Live-Stand: Commit `a30d83c2`
(Facebook-Story-Fix, 16.09.2026, laut vorherigem Chip deployed, hier nicht erneut verifiziert
bis zum ersten TPX-Deploy).

**Deploy-Freigabe 17.09.2026 (Olli, explizit im Chat)**: "darfst wenn es sauber ist selbst
mergen und commit und push und deploy machen, dadurch schaltest du testmodus frei der auf dem
oliver workspace testen kann mit pruefung ob gepostet und deletbar". Hebt den generellen
Deploy-Gate-Stopp (siehe Olli-Touchpoints unten) fuer DIESEN konkreten Moment auf. Gilt NICHT
als generelle Dauerfreigabe fuer jeden kuenftigen Deploy dieser Welle, sondern als konkrete
Freigabe fuer diesen Schritt.

**Deploy DURCHGEFUEHRT 17.09.2026, 07:3x UTC**: `git pull --ff-only` auf `/opt/trypost/src`
(web02), 30 Commits (A4 #13, A5 #12, B1b #15, TPXB-01 #14, B1 #16, plus Doku-Commits),
`docker compose up -d --build app`, Migration `2026_09_17_120000_create_media_post_platform_table`
lief automatisch beim Container-Start (Batch 4, per `migrate:status` + `\dt` in Postgres
verifiziert). Health-Check: Container `trypost` healthy, `https://social.madevisible.io/login`
HTTP 200, `php artisan --version` bestaetigt Laravel 13.24.0 hochgefahren.

**Zweiter Deploy (Welle B) DURCHGEFUEHRT 17.09.2026**: alle 4 verbleibenden B2a/c/d/B4-PRs in
der empfohlenen Reihenfolge B2a→B2c→B2d→B4 gemergt (`27420c66`, `faa4cb31`, `a70f0b45`,
`ccb75401`), danach voller lokaler Pest-Sweep VOR Deploy gefahren (Pflicht, nie blind deployen).
Sweep zeigte 126 Fehlschlaege, beide Ursachen gefunden und auf main gefixt (`b484af8b`, siehe
Completed-Tabelle): 124 Umgebungsluecke (fehlende Passport-Keys in dieser lokalen Testinstanz,
keine echte Regression), 2 echte stale Test-Fixtures (`UnpublishPostToolTest.php` nutzte
LinkedIn als "unsupported"-Beispiel, seit B2c falsch) plus ein unabhaengiger Morph-Map-Fund
(`MediaPostPlatform` fehlte). Voller Sweep danach 4655 passed, 2 skipped, 0 failed. `git pull
--ff-only` auf web02 (`77927af` → `b484af8b`, keine Migration im Delta), `docker compose up -d
--build app`. Health-Check: Container `trypost` healthy, `https://social.madevisible.io/login`
HTTP 200, `php artisan --version` bestaetigt Laravel 13.24.0, Server-`git rev-parse HEAD`
bestaetigt `b484af8b` deployed.

**Live-Smoke-Test DURCHGEFUEHRT 17.09.2026 (Facebook, echte UI-Buttons, Oliver-Albrecht-Workspace).**
Chrome-MCP war in dieser Session nicht erreichbar, Safari-MCP (`mcp__safari__*`, Ollis echte
eingeloggte Session) als gleichwertige Alternative genutzt. Workspace ueber den echten
Workspace-Switcher (UI-Klick) auf "Oliver Albrecht" gewechselt (5 verbundene Accounts:
Facebook, Instagram-via-Facebook, LinkedIn, TikTok, YouTube Shorts, exakt die Welle-B-Plattformen
plus TikTok als Negativ-Kontrolle). Test-Post erstellt ("TEST POST: TPX Welle B Live-Smoke-Test"),
nur Facebook aktiviert (Instagram-Feed verlangt zwingend Media, reiner Text-Post scheitert an der
`requires_media`-Validierung, daher fuer diesen Lauf ausgeklammert), Olli-Freigabe per
AskUserQuestion vor dem echten Publish eingeholt. **Klick auf "Post now"** (echter UI-Button):
Post ging echt live auf Facebook (`oliveralbrecht.official`, `pfbid02pZijAr7...`), per direktem
Tab-Titel-Check verifiziert. **Klick auf "Unpublish"** im Post-Dropdown (echter UI-Button, ueber
den Bestaetigungs-Dialog "Unpublish post?"): Post-Status lokal zurueck auf Draft, und der
Facebook-Permalink zeigt seitdem "Dieser Inhalt ist momentan nicht verfuegbar ... es kann auch
sein, dass der Content inzwischen geloescht wurde", also echte Loeschung auf der Plattform
bestaetigt. **`FacebookPublisher::delete()` (B2a) ist damit End-to-End ueber echte UI-Buttons auf
Produktion verifiziert.**

Ein Nebenbefund bestaetigt live einen bereits im B4-Review dokumentierten Nicht-Blocker: der erste
Klick auf den "Unpublish"-Bestaetigungsbutton meldete einen `safari-helper timeout` auf Tool-Seite,
das Request kam aber serverseitig durch (Post wurde real unpublished). Ein zweiter Klick (weil der
Dialog laut Snapshot noch offen schien) traf denselben Endpoint auf einem bereits unpublishten Post
(0 Kandidaten-Rows mehr), was laut `PostController::unpublish()`s bekannter Logik
(`$result['unpublished'] === []` -> immer die "unsupported"-Flash-Meldung, unabhaengig vom echten
Grund) faelschlich "This post can't be unpublished automatically" anzeigte, obwohl der erste Klick
laengst erfolgreich war. Kein neuer Bug, bestaetigt nur den bereits bekannten B4-Nicht-Blocker
("unpublish() mapped `failed`-only faelschlich auf die `unsupported`-Meldung") jetzt auch fuer den
Fall "leere Kandidatenliste durch Doppel-Klick" TOTAL. Verifikation lief ueber die tatsaechliche
DB/API-Antwort und den Facebook-Permalink, nicht ueber die (irrefuehrende) Toast-Meldung. Test-Post
danach vollstaendig aufgeraeumt (aus Drafts geloescht, "delete"-Bestaetigung ueber echten UI-Dialog).

**Nachtrag 17.09.2026, Chrome-MCP wieder erreichbar: LinkedIn und Instagram zusaetzlich live
getestet, auf Olli-Wunsch ("chrome mcp geht wieder" -> alle drei verbleibenden Plattformen
live testen).**

**LinkedIn: End-to-End erfolgreich verifiziert.** Test-Post ("TEST POST: TPX Welle B
Live-Smoke-Test LinkedIn"), nur LinkedIn aktiviert (API-Direct-Call statt UI-Toggle, siehe unten),
Olli-Freigabe eingeholt, "Post now" geklickt: Post ging live (`urn:li:share:7506425294401142784`).
"Unpublish" im Dropdown geklickt, Bestaetigungsdialog bestaetigt: LinkedIn-Permalink zeigt danach
"Der Beitrag kann nicht angezeigt werden", also echte Loeschung bestaetigt.
`AbstractLinkedInPublisher::delete()` (B2c) End-to-End auf Produktion verifiziert. Test-Post
danach ueber echten Delete-Dialog aufgeraeumt.

**Instagram: Post + Live-Verifikation erfolgreich, Unpublish BLOCKIERT durch einen echten,
kritischen Meta-Permission-Befund** (siehe Olli-Touchpoints-Tabelle oben, Zeile
"`instagram_manage_contents`-Scope von Meta abgelehnt"). Test-Post mit generiertem Testbild
(lokal erzeugtes PNG, ueber Stock-Photos-Suche kamen keine Treffer, direkt hochgeladen) via
"Post now" live auf Instagram gestellt (`instagram.com/p/DdZm3KBFIk_/`, per Tab-Check
verifiziert). "Unpublish" schlug wiederholt fehl mit der Toast-Meldung "This post can't be
unpublished automatically". Root-Cause NICHT der B4-Toast-Bug von oben (kein leerer
Kandidaten-Doppelklick-Fall), sondern ein echter `missingDeleteScopes()`-Treffer: der
verbundene Instagram-Account hat den von B2a neu geforderten Scope
`instagram_manage_contents` nicht (Server-Scope-Check bestaetigt: `["public_profile",
"pages_show_list","pages_read_engagement","business_management","instagram_basic",
"instagram_content_publish","instagram_manage_insights"]`, kein `instagram_manage_contents`
darin). Reconnect-Versuch ueber die UI (Connections -> Instagram -> Connect another ->
Facebook Pages) von Olli selbst durchgefuehrt: Meta liefert bei diesem Scope live
`Invalid Scopes: instagram_manage_contents. This message is only shown to developers.` Der
Permission-Name selbst existiert real in Metas Katalog, ist fuer DIESE App aber noch nicht auf
Advanced Access freigegeben (Meta App Review noetig). **Instagram-Test-Post manuell von Olli
direkt auf Instagram geloescht** (kein TryPost-Automatismus moeglich solange der Scope fehlt),
per Permalink-Check verifiziert ("Diese Seite ist leider nicht verfuegbar ... die Seite wurde
entfernt").

**Adversarialer Nachtest 17.09.2026 (auf Olli-Nachfrage "Scopes sauber beantragt?"): den
kompletten Scope-Wiring-Pfad fuer ALLE Plattformen nochmal geprueft, nicht nur Instagram.**
Facebook: `requiredDeleteScopes()` verlangt `pages_manage_posts`, dieselbe Scope wie
`requiredPublishScopes()`, laengst vor dieser Welle gewaehrt (Server-Check bestaetigt),
erklaert warum der Facebook-Live-Test ohne jede Reibung durchlief. LinkedIn: kein
Delete-Scope-Gate im Code (`default => []`), LinkedIn-Delete nutzt real dieselbe
`w_member_social`-Berechtigung wie Publish, ebenfalls laengst vorhanden, erklaert den
reibungslosen LinkedIn-Live-Test. YouTube: ebenfalls kein Delete-Scope-Gate im Code, ABER
Server-Check bestaetigt dass der Account `youtube.force-ssl` (die von Google fuer
`videos.delete` tatsaechlich verlangte Scope) bereits besitzt, waere also technisch nutzbar,
nur nicht live geklickt. Erkannte Haertungs-Luecke (kein Blocker fuer diesen Account, aber
architektonisch ungeschuetzt): ohne ein `requiredDeleteScopes()`-Eintrag fuer YouTube wuerde
ein Account OHNE `force-ssl` nicht die freundliche "Missing permissions, bitte neu verbinden"
Meldung bekommen, sondern einen rohen Google-API-Fehler, siehe Discovered-Backlog unten.

**Instagram-Scope-Blocker zusaetzlich DOPPELT verifiziert, nicht nur als OAuth-Rejection
angenommen.** Zweiter Instagram-Testpost erstellt (API, `01a0b0ec-b97c-...`, echtes Testbild),
live publiziert (`instagram.com/p/DdZp40ClCgH/`). Danach die echte Meta Graph-API `DELETE`
**direkt per `Http::delete()` in `tinker`** aufgerufen, mit dem Account's aktuellem, echtem
Access-Token, UNTER UMGEHUNG von TryPosts eigenem `missingDeleteScopes()`-Gate. Ergebnis: Meta
selbst lehnt den Aufruf ab, `HTTP 400`, `{"error":{"message":"(#10) Insufficient permissions to
access this data","code":10,"type":"OAuthException"}}`. **Das widerlegt die Hypothese, der
Code-Gate koennte ueberfluessig streng sein**: selbst OHNE TryPosts eigenen Scope-Check haette
Meta die Loeschung mit den aktuell gewaehrten Scopes (`instagram_basic`,
`instagram_content_publish`, `instagram_manage_insights`) verweigert. B0s urspruengliche
Recherche war korrekt, `instagram_manage_contents` (oder eine aequivalente, noch nicht
freigegebene Permission) ist wirklich erforderlich. Zweiter Testpost ebenfalls manuell von
Olli auf Instagram geloescht (derselbe Weg wie beim ersten, TryPost kann ihn strukturell
nicht selbst entfernen), per Permalink-Check bestaetigt ("Diese Seite ist leider nicht
verfuegbar ... die Seite wurde entfernt"). Beide Instagram-Test-Posts damit vollstaendig
aufgeraeumt.

**Fazit zur Frage "ist alles bewiesen und sauber, Scopes sauber beantragt?"**: Facebook und
LinkedIn sind vollstaendig bewiesen (End-to-End live), sauber weil sie bereits vorhandene,
laengst genehmigte Scopes wiederverwenden, kein Nacharbeit noetig. YouTube ist scope-seitig
sauber fuer den getesteten Account (force-ssl vorhanden), aber nicht live geklickt und hat
eine kleine architektonische Haertungs-Luecke (siehe Backlog). Instagram ist NICHT sauber:
der Delete-Scope ist doppelt verifiziert real blockiert (OAuth-Rejection UND direkter
API-Call), das ist ein echter, externer Blocker (Meta App Review), kein Code-Fix moeglich.

**YouTube: NICHT live getestet.** Ein echtes Kurzvideo-Upload war fuer den Umfang dieser Session
nicht verhaeltnismaessig, `YouTubePublisher::delete()` bleibt ueber die bestehende 12/12-Test-Suite
(inkl. der B2d-Testbarkeits-Naht fuer Googles Guzzle-Transport) abgedeckt, aber ohne echten
Live-Klick. Bei Bedarf als eigener Folge-Schritt nachholbar.

## Neues Feature 18.09.2026: Facebook-Story Foto-zu-Video + optionale KI-Musik (PR #22)

Ausserhalb der urspruenglichen Goal-Etappen, auf Ollis Wunsch nach Welle A/B autonom gebaut
(Nacht-Gate-Runde 2, "Beides sofort autonom, volles Feature"). Von einem Hintergrund-Agenten
implementiert, per Agent-Review-Runde 1+2 (verschiedene Modelle) geprueft, dann per echtem
Live-Smoke-Test auf Production verifiziert, wie bei der gesamten Welle Pflicht.

**Kern-Feature**: `FacebookPublisher::publishStory()` akzeptiert jetzt Fotos, konvertiert sie
serverseitig per ffmpeg zu einem Standbild-Video (`ImageToVideoConverter`, `docker/Dockerfile`
bekam `ffmpeg` neu). Vorher war ein reines Foto fuer Facebook Stories hart abgelehnt.

**Optionales Add-on**: `StoryMusicGenerator` (Gemini Vision + Lyria, komplett best-effort,
faellt bei JEDEM Fehler still auf stille Audiospur zurueck), per `story_music_description`
Meta-Feld (zentral in `PostPlatformMetaRules`, alle drei Entry-Points), standardmaessig AUS
(`FACEBOOK_STORY_AI_MUSIC_ENABLED=false`, echte Kosten $0.08/Song).

**Zwei echte Bugs erst beim Live-Test gefunden, nicht von den Code-Reviews** (Beleg fuer die
Buttons-Pflicht dieser ganzen Welle):
1. `ContentType::supportsImage()` liess FacebookStory weiter auf `false` stehen (Alt-Regel von
   vor diesem PR), der "Post now"-Button war client-seitig komplett blockiert, kein Request
   feuerte je. Fix: `supportsImage() => true` fuer FacebookStory (Reel bleibt `false`), zwei
   Alt-Tests die die alte Regel pinnten aktualisiert.
2. **Facebook Stories lassen sich grundsaetzlich NICHT per Graph-API loeschen.** Meta lehnt
   `DELETE /{video-id}` fuer Stories mit "Unsupported delete request" ab, obwohl derselbe Call
   fuer Reels/Feed-Posts funktioniert (unabhaengig bestaetigt: identischer Fehler in
   `github.com/restfb/restfb/issues/1469`, offenes, ungeloestes Issue). Fix:
   `ContentType::supportsDelete()` neu (nur FacebookStory = false), `UnpublishPost::execute()`
   routet Stories jetzt in den bestehenden `unsupported`-Bucket (wie TikTok) statt einen rohen
   Graph-API-Fehler als `failed` zu zeigen.

**Live-Smoke-Test bestanden**: echter Facebook-Story-Post aus einem reinen JPEG-Foto ueber die
echte UI published, Story real auf Facebook sichtbar
(`facebook.com/stories/1050803071750445/1093500393652407`). Unpublish-Versuch danach ueber den
echten Button bestaetigt Fix #2: kein Fehler mehr im Log, Post bleibt korrekt "published"
(nichts wurde tatsaechlich entfernt, by design, kein Bug). Test-Story bleibt bis zum
natuerlichen 24h-Ablauf sichtbar (klar als Test markiert).

**Deploy**: 3 Commits nacheinander deployed (`acafa322` PR #22 selbst, `457a7b2e` Fix #1,
`96b194af` Fix #2), jedes Mal `git pull --ff-only` auf `/opt/trypost/src` + `docker compose up
-d --build app` unter `/opt/trypost` (NICHT `/opt/trypost/src`, siehe Olli-Touchpoints-Vorfall
unten), Health-Check jedes Mal gruen. Merge und Deploy liefen fuer dieses Feature autonom
(Nacht-Gate-Runde 2, Deploy-Stopp explizit fuer dieses eine Feature aufgehoben).

**Vorfall waehrend des ersten Deploys**: `docker compose up -d --build app` aus
`/opt/trypost/src` (statt `/opt/trypost`) traf das REPO-eigene `compose.yaml` (Dev-Setup) statt
das Produktions-Compose, erzeugte kurzzeitig fremde Container (`src-app-1` etc.) und kollidierte
mit dem laufenden Produktions-Redis auf Port 6379. Sofort per `docker compose down` in
`/opt/trypost/src` aufgeraeumt, kein Datenverlust, kein Effekt auf `trypost`/`trypost-pgsql`/
`trypost-redis`. Der korrekte Produktions-Pfad ist immer `/opt/trypost` (compose.yml dort baut
`context: ./src`).
