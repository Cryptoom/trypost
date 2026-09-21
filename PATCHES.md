# Lokale Patches · trypost (Fork)

Privater Fork `Cryptoom/trypost` (origin). Upstream `trypostit/trypost` als Remote `upstream`.
Angelegt 2026-08-25 als Infrastruktur-Vorbereitung (noch KEIN aktiver Patch), Muster identisch
zu `~/voice-clone/whatsapp-mcp/PATCHES.md` und dem telegram-mcp-Fork.

Lizenz-Hinweis (wichtig, anders als bei den anderen beiden Forks): TryPost ist **AGPL-3.0**,
nicht MIT/Apache. AGPL hat Netzwerk-Copyleft: sobald eine modifizierte Version selbst gehostet
UND von Dritten (echten Kunden-Workspaces, nicht nur Digital Mind Agency/Jasmin intern) genutzt
wird, muss diesen Dritten der modifizierte Quellcode zugaenglich gemacht werden (z.B. Link im
Footer/Impressum zum Fork-Repo). Fuer den aktuellen Piloten (nur wir + Jasmin) unkritisch, vor
dem ersten echten Kunden-Workspace mit gepatchtem TryPost aber Pflicht-Punkt.

Update-Flow: `git fetch upstream && git merge upstream/main`, danach jeden Patch unten anhand
seines Marker-Strings gegenpruefen (`grep` reicht meist), erst dann `docker compose up -d --build`
auf web02 (Update-Klasse B/C-Aequivalent, siehe `~/.claude/rules/web02-docker-updates.md`, dort
noch nachtragen sobald der erste echte Patch aktiv ist).

## Aktive Patches

### Patch 1 · update-workspace-tool

Fuegt ein schreibendes MCP-Tool fuer die Workspace-Brand-Settings hinzu. Upstream hat nur
`GetWorkspaceTool` (read-only, `#[IsReadOnly]`), keinen API- oder MCP-Weg um `name`,
`brand_website`, `brand_description`, `brand_voice_traits`, `brand_color`, `background_color`,
`text_color`, `brand_font`, `image_style` oder `content_language` programmatisch zu setzen. Ohne
diesen Patch braucht jedes automatisierte Kunden-Onboarding (neuer Workspace, Brand per Skript
vorbefuellen) einen manuellen Umweg ueber die UI.

- **Dateien**:
  - `app/Mcp/Tools/Workspace/UpdateWorkspaceTool.php` (neu). Validiert dieselben Regeln wie
    `App\Http\Requests\App\Workspace\UpdateWorkspaceRequest`, aber PATCH-Semantik (`sometimes`
    statt `required`, nur uebergebene Felder werden geschrieben). Autorisierung ueber
    `AuthorizesMcpTool::authorizeCurrentWorkspace($request, 'update', ...)`, spiegelt
    `WorkspaceController::updateSettings()`.
  - `app/Mcp/Servers/TryPostServer.php` (Import + Registry-Eintrag unter "Workspace").
- **Marker-String zum Wiedererkennen nach `git merge upstream/main`**: Klassenname
  `UpdateWorkspaceTool` (Datei existiert upstream nicht) plus der Registry-Kommentar
  `// Workspace` in `TryPostServer.php` (dort pruefen ob `UpdateWorkspaceTool::class` noch
  direkt nach `GetWorkspaceTool::class` steht).
- **Test**: `tests/Feature/Mcp/WorkspaceToolTest.php` (4 neue Tests: valides Update, ungueltige
  `brand_color`, Cross-Workspace-Isolation, Ability-Check fuer Member ohne Admin/Owner-Rolle).
- Bricht bei einem Merge NUR falls upstream `UpdateWorkspaceRequest`, `WorkspaceResource` oder
  die `update`-Ability in `WorkspacePolicy` umbenennt/entfernt, dann Patch-Regeln nachziehen.
- **Deployed** 25.08.2026: `/opt/trypost` auf web02 laeuft seit diesem Patch auf dem Fork-Remote
  `Cryptoom/trypost` (vorher `trypostit/trypost` upstream), `docker compose up -d --build`
  erfolgreich, live verifiziert (Klasse instanziierbar im Produktions-Container).

### Patch 2 · madevisible-brand-token-reskin

Ersetzt die komplette upstream Gumroad-Optik (warmes Cream, Ink-Border, Violett-Akzent,
Figtree/Instrument-Serif, harte Offset-Shadows) in der `:root`-Deklaration durch die
madevisible.io-Brand-Tokens (tiefes Teal-Primary, Navy-Text, Gold-Akzent, weiche getoente
Shadows, Geist-Font-Stack). Concept-Only-Reskin, damit App und die madevisible.io-Kunden-Instanz
optisch zusammengehoeren.

- **Datei**: `resources/css/app.css`, die `:root`-Deklaration (aktuell Zeilen ca. 16-80).
- **Marker-Strings zum Wiedererkennen nach `git merge upstream/main`**: die konkreten
  Hex-Werte `#0c6e6d` (`--primary`/`--ring`/`--sidebar-primary`), `#0a2540` (`--foreground`),
  `#c99a5c` (`--accent`) und `#a67c3f` (`--chart-3`, WCAG-korrigiert von urspruenglich `#c99a5c`
  in Review Round 1). Keiner dieser Werte kommt in der upstream-Fassung vor (dort `#7c3aed`
  Primary/Violett, `#0a0a0a` Foreground/Ink, `#faf8f5` Background). Ebenso der Kommentar
  `madevisible.io brand tokens (Mediterranean Light / Premium)` direkt unter `:root {`.
- **Bruchbedingung**: Upstream aendert dieselbe `:root`-Block-Struktur in `app.css` (neue
  Variablen, umbenannte Tokens, andere Reihenfolge) und `git merge upstream/main` laeuft
  **konfliktfrei** durch. Ein konfliktfreier Merge ueberschreibt in diesem Fall die
  Fork-Farbwerte stillschweigend mit den upstream-Originalwerten, weil beide Seiten dieselben
  Zeilen anfassen und Git sonst laut einen Konflikt melden wuerde. Nach jedem Merge darum
  gezielt gegen die obigen Hex-Werte greppen (`grep -c '#0c6e6d' resources/css/app.css`), nicht
  nur auf einen Merge-Konflikt verlassen.
- Betrifft NUR `:root` (Light-Theme-Tokens). Ein eventueller `.dark`-Block bleibt unangetastet,
  falls upstream einen ergaenzt, muesste der Reskin dort nachgezogen werden.

### Patch 3 · sidebar-referral-discord-entfernung  (Status: upstream-merged, seit 2026-09-16)

Entfernte die zwei untersten Bottom-Nav-Eintraege der Sidebar ("Earn 30% referral" und "Discord
community"), auf Ollis ausdruecklichen Wunsch (das TryPost-eigene Affiliate-/Community-Programm
soll im Digital-Mind-Agency-Weissabel-Kontext nicht auftauchen).

**Obsolet seit dem Merge von upstream `main` am 2026-09-16** (201 Commits, v2.14.0-Basis auf
den Stand nach `e28f42d0` gezogen): Upstream hat den kompletten `bottomNavItems`-Block
(inkl. Docs-Link) aus `AppSidebar.vue` entfernt, kein Bottom-Nav-Footer mehr vorhanden. Damit
gibt es weder Referral- noch Discord-Link mehr, unser Patch ist gegenstandslos. Beim Mergen kam
es zu einem echten Konflikt (Git sah beide Seiten dieselben Zeilen anfassen), nicht zum
befuerchteten stillen Ueberschreiben. Aufgeloest durch Uebernahme der upstream-Loeschung.
Zusaetzlich ein unbenutzter `IconBolt`-Import entfernt (Leiche aus einer frueheren
Fork-Bearbeitung, nicht Teil dieses Patches, nirgends mehr referenziert).

- **Ehemalige Datei**: `resources/js/components/AppSidebar.vue`.
- **Pruefung falls upstream den Bottom-Nav-Footer je wieder einfuehrt**: `grep -n
  "trypost.it/discord\|affiliates.trypost.it" resources/js/components/AppSidebar.vue` muss
  weiterhin leer bleiben.

### Patch 4 · madevisible-legal-footer-links  (Status: upstream-merged, seit 2026-09-16)

Grund: TikToks App-Review lehnte "madevisible.io Social" am 04.09.2026 ab, unter anderem weil
`social.madevisible.io/login` **ueberhaupt keine** Privacy-/ToS-Links zeigte (live per Playwright
mit frisch gecleartem Cookie-Zustand als echter ausgeloggter Besucher verifiziert). Root-Cause:
Upstream haengt den einzigen Legal-Footer-Block (`auth.legal`-Uebersetzung) an
`v-if="!isSelfHosted"` in `Register.vue`, und `Login.vue` hatte den Block gar nicht erst. Unser
Docker-Deployment laeuft mit `trypost.self_hosted=true` (Betreiber-Modell, nicht Multi-Tenant-
SaaS wie trypost.it selbst), darum blieb der Footer bei uns immer unsichtbar, upstream-seitig
vermutlich bewusst so gedacht ("Self-Hoster bringt eigene Legal-Links mit"), was fuer uns aber
nie zutraf.

- **Dateien**:
  - `lang/*/auth.php` (alle 16 Locales): der `legal`-String zeigte in JEDER Sprache hart auf
    `https://trypost.it/terms` und `https://trypost.it/privacy`. Beide URLs auf
    `https://madevisible.io/agb/` bzw. `https://madevisible.io/privacy/` umgestellt (reiner
    URL-Tausch, uebersetzter Fliesstext unveraendert). Diese beiden Seiten nennen
    "madevisible.io Social" seit `Cryptoom/digital-mind-agency#396` explizit beim Namen.
  - `resources/js/pages/auth/Register.vue`: `v-if="!isSelfHosted"`-Guard auf dem Legal-Footer-Div
    entfernt (zeigt jetzt immer), dadurch wurde die `isSelfHosted`-Computed-Variable unbenutzt und
    wurde mitentfernt. `page`/`usePage()` bleiben (weiterhin fuer `hasSocial` gebraucht).
  - `resources/js/pages/auth/Login.vue`: denselben Legal-Footer-Block (identisches Markup wie
    `Register.vue`, `v-html="$t('auth.legal')"`) direkt nach dem `</Form>` neu ergaenzt, vorher
    gab es dort ueberhaupt keinen.
- **Marker-Strings zum Wiedererkennen nach `git merge upstream/main`**: `madevisible.io` in
  `lang/en/auth.php`s `legal`-Zeile (kommt upstream nicht vor). In `Login.vue`: das
  `v-html="$t('auth.legal')"`-Div direkt vor dem schliessenden `</div></AuthBase></template>`
  (existiert upstream nicht in dieser Datei). In `Register.vue`: Abwesenheit von
  `v-if="!isSelfHosted"` auf dem Legal-Div (upstream hat es).
- **Bruchbedingung**: Upstream aendert den `auth.legal`-Text selbst (neue Formulierung, neue
  Platzhalter) und `git merge upstream/main` ueberschreibt konfliktfrei unsere URL-Werte mit den
  originalen `trypost.it`-URLs zurueck, weil beide Seiten dieselbe Zeile aendern koennten aber
  Git bei reinem Text-Unterschied idR einen Konflikt meldet (text-basiertes 3-Way-Merge), pruefen
  nach jedem Merge trotzdem gezielt: `grep -rn "trypost.it/terms\|trypost.it/privacy" lang/*/auth.php`
  muss leer bleiben. Ergaenzt upstream `Login.vue`/`Register.vue` selbst einen aehnlichen
  Legal-Footer oder aendert die `isSelfHosted`-Logik grundlegend, Patch-Platzierung manuell
  gegenpruefen statt blind erneut einzufuegen.
- **Test**: keine automatisierten Tests vorhanden (reine Template-/Copy-Aenderung), Verifikation
  ueber Live-Check nach Deploy (siehe unten).
- **Deploy-Falle (Review Round 2)**: `lang/*/auth.php` wirken erst nach einem ECHTEN Frontend-
  Build. `vite.config.ts` nutzt `laravel-vue-i18n/vite`, das die PHP-Locales zur Build-Zeit nach
  `lang/php_*.json` kompiliert (die JSON-Dateien selbst stehen in `.gitignore`). Ein reines
  Kopieren der geaenderten PHP-Datei in den laufenden Container plus Neustart behaelt die alten
  `trypost.it`-URLs im bereits gebundelten JSON, ohne Fehlermeldung. Zwingend
  `docker compose up -d --build` (voller Rebuild), NICHT nur `restart`.
- **Deployed**: 25.08.2026 (Ursprungspatch, `trypost.it`-URLs hart im Code). Abgeloest durch den
  env-var-Ansatz unten, deployed 16.09.2026.

**Obsolet seit dem Merge von upstream `main` am 2026-09-16**: Upstream hat das Problem, das
diesen Patch ausloeste, sauber geloest, mit einer besseren Loesung als unserer eigenen.
`config/trypost.php` hat jetzt `legal.terms_url`/`legal.privacy_url` (env-konfigurierbar via
`LEGAL_TERMS_URL`/`LEGAL_PRIVACY_URL`, Default weiterhin `trypost.it`), geteilt via
`HandleInertiaRequests`-Middleware als `page.props.legal.{terms,privacy}`. Beide Auth-Seiten
nutzen jetzt eine gemeinsame Komponente `resources/js/components/auth/LegalLinks.vue`
(`Login.vue` UND `Register.vue`, der `isSelfHosted`-Guard ist komplett weg, kein
Sichtbarkeits-Unterschied zwischen den beiden mehr). Der upstream-Kommentar in
`config/trypost.php` nennt explizit "Platform app reviews (TikTok explicitly) require Terms
and Privacy links to be clearly visible", genau unser Grund.

- **Neue Loesung, kein Code-Patch mehr**: `lang/*/auth.php` auf upstreams `:terms_url`/
  `:privacy_url`-Platzhalter zurueckgesetzt (16 Locales, uebersetzter Text unveraendert),
  `Login.vue`/`Register.vue` nutzen `<LegalLinks />` wie upstream.
- **PFLICHT vor dem naechsten Deploy auf web02**: `LEGAL_TERMS_URL=https://madevisible.io/agb/`
  und `LEGAL_PRIVACY_URL=https://madevisible.io/privacy/` in `/opt/trypost/.env` (bzw.
  `docker-compose.yml`-Env-Block) setzen, SONST fallen die Links beim naechsten Container-Rebuild
  stillschweigend auf `trypost.it/terms`/`trypost.it/privacy` zurueck (Upstream-Default). Noch
  NICHT auf web02 gesetzt, Stand dieses Merges.
- **Pruefung nach dem naechsten Merge**: `grep -rn "trypost.it/terms\|trypost.it/privacy"
  lang/*/auth.php` MUSS leer bleiben (Platzhalter, keine harten URLs mehr, also triviales Grep).
  Der eigentliche Wert kommt jetzt aus der `.env` auf dem Server, nicht mehr aus dem Repo.

## Geprueft und NICHT gepatcht: is_aigc-Composer-Toggle (25.08.2026)

Der urspruenglich fuer diesen Fork geplante Patch (TikTok-`is_aigc`-Toggle im Post-Composer,
fuer die KI-Kennzeichnungs-Pflicht) ist **obsolet**: das Feature existiert bereits vollstaendig
upstream (`resources/js/components/posts/editor/TikTokSettings.vue`, Checkbox `isAigc`;
`app/Support/PostPlatformMetaRules.php`, `platforms.*.meta.is_aigc`; `TikTokPublisher.php`,
setzt `$postInfo['is_aigc']`; auch im MCP `CreatePostTool.php`). Kein Patch noetig. Meta/
Instagram und YouTube haben kein aequivalentes API-Feld (nur Checklisten-Eintrag), das bleibt
eine offene Luecke, aber kein Fork-Patch-Kandidat solange die Plattformen selbst kein API-Feld
anbieten.

## Wie ein neuer Patch hier reinkommt

1. Aenderung im Fork machen, committen, Marker-String im Commit-Message + hier dokumentieren
   (Datei, Zeile/Funktion, WARUM, welcher Marker-String das Wiedererkennen nach einem Merge
   erlaubt).
2. `web02`-Deploy: `/opt/trypost` laeuft als lokaler Build aus Git-Checkout (Update-Klasse C,
   siehe `~/.claude/CLAUDE.md` Docker-Tabelle), NICHT das published Image. Seit Patch 1
   (25.08.2026) laeuft der Server-Checkout gegen `Cryptoom/trypost` (Fork), nicht mehr gegen
   upstream.
3. Nach jedem `git merge upstream/main`: alle Marker-Strings unten gegenpruefen, dieser
   Abschnitt fasst dann "Stand nach Merge <datum>" analog zum whatsapp-mcp-Muster.

## Stand nach Merge 2026-09-16

- 201 Commits von `upstream/main` gemergt (Basis vorher: v2.14.0-Aequivalent nach Patch 1,
  Ziel: Commit `e28f42d0`, ueber v1.0.8/v1.0.9 hinweg). Composer-`vendor`/`node_modules` waren
  lokal nicht vorinstalliert, mit `PATH=.../php8.4.17:$PATH composer install` (MAMP-PHP 8.4,
  System-PHP 8.3 reicht nicht mehr, upstream verlangt jetzt PHP >=8.4) und `npm install`
  nachgeholt.
- 19 echte Datei-Konflikte (16x `lang/*/auth.php`, `AppSidebar.vue`, `Login.vue`,
  `Register.vue`). KEIN stilles Ueberschreiben, alle vier PATCHES.md-Bruchbedingungen haben
  wie dokumentiert einen echten Git-Konflikt ausgeloest statt zu schweigen.
- Patch 1 (`UpdateWorkspaceTool`) und Patch 2 (Brand-Reskin `app.css`) sind AUTOMATISCH
  konfliktfrei gemergt UND intakt (Marker-Check bestanden: `UpdateWorkspaceTool::class` steht
  weiter in `TryPostServer.php`, `#0c6e6d` weiter 6x in `app.css`).
- Patch 3 und Patch 4 sind **upstream-merged** (siehe dort), eigener Code dafuer entfernt.
- Upstream hat parallel das komplette Automations-Modul entfernt (`d8149ef0`, viele geloeschte
  Dateien unter `app/Actions/Automation/*`, `tests/Feature/Automation/*` etc.), das ist reine
  Upstream-Entscheidung, kein Fork-Patch betroffen davon.
- **Verifikation**: keine Merge-Marker mehr im Repo (`grep -rl '^<<<<<<<'` leer), `php -l` auf
  allen 16 `lang/*/auth.php` sauber, `vue-tsc --noEmit` zeigt fuer `Login.vue`/`Register.vue`/
  `AppSidebar.vue` KEINE eigenen Fehler (nur die repo-weiten, vorbestehenden
  `Cannot find module '@/routes/...'`-Fehler, die von fehlenden Laravel-Wayfinder-generierten
  Typen kommen, weil `php artisan package:discover` lokal ohne volle `.env`/DB scheitert, nicht
  von diesem Merge). Kein `npm test`/`composer test` gefahren (keine lokale Postgres-Instanz
  fuer die Feature-Tests aufgesetzt) und kein Vite-Build gefahren, siehe Naechste Schritte.
- **Gepusht + deployed 16.09.2026** (Olli-OK): `origin/main` auf `88d497d1`, web02
  `/opt/trypost/src` per `git pull --ff-only` synchronisiert, `LEGAL_TERMS_URL`/
  `LEGAL_PRIVACY_URL` in `/opt/trypost/compose.yml` gesetzt (Backup:
  `compose.yml.bak-pre-legal-env-20260916-143356`), `docker compose up -d --build app` erfolgreich
  (Container `trypost` healthy, `php artisan migrate --force` meldete "Nothing to migrate").
  Live-Check bestanden: `curl https://social.madevisible.io/login` liefert HTTP 200 mit
  `"legal":{"terms":"https:\/\/madevisible.io\/agb\/","privacy":"https:\/\/madevisible.io\/privacy\/"}`
  in den Inertia-Props, Sidebar-Screenshot (eingeloggte Session) zeigt keinen
  Referral-/Discord-/Docs-Footer mehr.

## Geplant, noch nicht gepatcht: Medien pro Plattform statt pro Post (16.09.2026)

Ausloeser: PlayCraft-Story-Draft ("Fresh Toys Just Arrived") bekam versehentlich Bild UND Video
gleichzeitig angehaengt. `facebook_story` akzeptiert gar keine Bilder, beide Story-Formate
(`facebook_story`, `instagram_story`) erlauben nur 1 Media-Item. Fix war ein komplett neuer Post
nur mit dem Video, weil es keinen Weg gibt, ein einzelnes Media-Item aus einem Post zu entfernen
oder Media pro Plattform unterschiedlich zuzuweisen.

**Ist-Zustand verifiziert (korrigiert 17.09.2026)**: `Post.media` ist eine JSON-Array-Spalte
(`'media' => 'array'` Cast, `app/Models/Post.php:49`), kein `morphMany`. Der Zugriff laeuft ueber
den `mediaItems()`-Attribute-Accessor (`app/Models/Post.php:57-65`), der jedes Array-Item per
`MediaItem::fromArray()` in ein DTO wandelt. `Post` nutzt den `HasMedia`-Trait
(`app/Models/Traits/HasMedia.php`, echte `morphMany`-Relation `media()`) NICHT, den haben nur
`User` und `Workspace` (Avatar/Logo/Asset-Collections). `PostPlatform` hat keine eigene
Media-Relation. `ContentTypeCompatibleWithMedia::media()` prueft fuer JEDE aktivierte Plattform
dieselbe Post-Media-Liste, ohne Filterung. Alle 13 Publisher-Services
(`app/Services/Social/*Publisher.php`) konsumieren dieselbe ungefilterte Liste beim Publish-Call.
Das Frontend (`useMedia.ts`/`useMediaRules.ts`) spiegelt dieselbe ungescopte Logik. Kein MCP-Tool
kennt eine Plattform-Zuordnung fuer Media.

**Ziel-Design**: optionale Pivot-Tabelle `media_post_platform` (`media_id`, `post_platform_id`).
Leere Zuordnung = gilt weiter fuer alle Plattformen (Default, rueckwaertskompatibel). Mit
Zuordnung gilt ein Media-Item nur fuer die genannten Plattformen, z.B. Bild nur fuer Instagram,
Video nur fuer Facebook/TikTok im selben Post.

**Aufwand-Einschaetzung**: kein Ein-Sitzungs-Patch. Migration + Model-Relation ist klein, die
Validierungs-Anpassung (Backend + Frontend-Spiegel) ist mittel, das Umstellen aller 13 Publisher
auf gefilterte Media ist der groesste Posten (viele Dateien, gleiches Muster, aber jede braucht
eigenen Test), dazu 4 MCP-Tools erweitern und eine neue Vue-Editor-UI (Toggle pro Media-Item pro
Plattform). Eher eine eigene Chip-Welle als ein einzelner Patch.

**AGPL-Hinweis, jetzt relevant, nicht erst "vor dem ersten Kunden"**: PlayCraft (Menelaos
Georgiou) ist bereits ein echter, zahlender Kunden-Workspace auf dieser Instanz. Sobald ein
Patch (dieser oder ein anderer) live laeuft, waehrend ein echter Kunde die Instanz nutzt, greift
die AGPL-Netzwerk-Copyleft-Offenlegungspflicht (Footer-/Impressum-Link zum Fork-Repo) bereits
jetzt, nicht erst bei diesem Feature. Vor dem Umsetzen dieses Plans mit Olli klaeren.

**Empfehlung**: technisch sauber machbar, aber kein Quick-Win. Sinnvoll bei wiederkehrendem
Bedarf (mehrere Kunden mit unterschiedlichen Plattform-Formaten im selben Post), nicht nur fuer
den PlayCraft-Einzelfall. Fuer den akuten Fall bleibt der pragmatische Workaround (zwei getrennte
Posts, einer pro Medien-Kombination) die schnellere Loesung, bis diese Welle ansteht.

## Upstream-Merge 2026-09-21 (4 Commits, Merge-Commit `3eb0a82a`)

Neue Upstream-Commits: LinkedIn-Personal-/Page-Post-Metriken (#364), TikTok-Post-Metriken (#363),
Instagram-Analytics-Fix (views statt retired plays/impressions, #362), Facebook-Video-Stories-
Upload-Fix ueber rupload mit file_url (#361).

**4 echte Konflikte**, alle geloest:

- `app/Mcp/Tools/Post/UpdatePostTool.php`: beide Seiten aenderten dieselbe `meta`-Feld-Beschreibung
  (wir: neues `media_ids`-Feld, Upstream: erweiterte `privacy_level`-Enum-Werte fuer TikTok).
  Beides kombiniert.
- `app/Services/Social/FacebookPublisher.php` + `tests/Feature/Services/Social/FacebookPublisherTest.php`:
  **bewusst UNSERE Version behalten (`git checkout --ours`), Upstreams Refactor NICHT uebernommen.**
  Upstream hat #361 als kompletten Klassen-Umbau geloest (neuer `postToGraph`-Helper quer durch
  alle publish*-Methoden, neuer asynchroner "hosted file"-Upload-Flow fuer Reels/Stories via
  `uploadVideo`/`startVideoUpload`/`uploadVideoFromUrl`/`waitForVideoUpload`, `requireVideo()`
  lehnt Fotos fuer Stories explizit ab). Unser Fork loest denselben zugrunde liegenden Bug
  (kaputter `video_file_chunk`-Transfer-Schritt) bereits SEIT LAENGEREM selbst, live verifiziert
  am 18.09.2026 (siehe Doc-Comment in `delete()`), UND hat ein Feature, das Upstream fehlt: Story-
  Foto-zu-Video-Konvertierung mit optionaler KI-Musik (`convertImageToStoryVideo`,
  `story_photo_duration_seconds`/`story_ai_music_enabled` in `config/trypost.php`). Ein Uebernehmen
  von Upstreams Version haette dieses Feature ersatzlos gestrichen. `postToGraph` kommt in unserem
  Code an keiner Stelle vor (0 Treffer), unsere Implementierung ist eine komplett unabhaengige
  Parallel-Loesung. Entscheidung: eigenes Update von Upstreams asynchronem Hosted-File-Ansatz auf
  Upstreams Refactor ist ein SEPARATES, bewusstes Vorhaben (naechster Schritt: pruefen ob Upstreams
  Ansatz zuverlaessiger ist als unser synchroner Rupload-Stream und ob sich beide Faehigkeiten
  vereinen lassen), nicht Teil dieses Merges.
- `config/trypost.php`: `story_photo_duration_seconds`/`story_ai_music_enabled` (unser Feature)
  UND Upstreams `rupload_host`-Key (aktuell ungenutzt, forward-kompatibel) behalten.

**Verifikation:** PHP-Syntax-Check aller 4 konfliktbehafteten Dateien sauber (`php -l`). Patch 1
(`UpdateWorkspaceTool`) und Patch 2 (Brand-Token-Hex-Werte) nach dem Merge per grep unveraendert
bestaetigt. **Volle Testsuite konnte lokal NICHT laufen**: `brianium/paratest` (Dev-Dependency)
verlangt PHP ~8.4.0, dieser Mac hat maximal PHP 8.3.x verfuegbar (Herd Lite 8.3.12, MAMP 8.3.30).
War schon VOR diesem Merge so (identisch in `composer.lock` von `HEAD~1`), keine Regression durch
den Merge. Der Docker-Container (`docker/Dockerfile`, `FROM php:${PHP_VERSION}-fpm-alpine`) hat
vermutlich PHP 8.4, Tests dort noch nicht gelaufen. Deploy auf web02 braucht separate Freigabe.

## Nachtrag 2026-09-21: volle Testsuite verifiziert

Lokale Testsuite lief nachtraeglich vollstaendig durch (PHP 8.4 via `brew install php@8.4`,
lokale Test-DB via `docker compose -f compose.test.yml up -d`): **4738 passed, 2 skipped, 0
failed** (594s). Bestaetigt den Merge-Commit `3eb0a82a` und den Doku-Commit `672f1f87` als
sauber, keine Regression.

## Nachtrag 2026-09-21: `assertRuploadUrl()` aus Upstream #361 backported

Empfehlung aus dem Facebook-Story-Upload-Vergleich (siehe Vault
`30-Snippets/trypost-facebook-story-upload-vergleich-2026-09-21.md`) umgesetzt: Upstreams
Host-Validierung des `upload_url`-Werts (`rupload.facebook.com`, https-only) uebernommen und in
`publishReel()`/`publishStory()` nach dem Start-Response-Parsing aufgerufen, VOR dem Transfer,
der den OAuth-Token an `upload_url` schickt. Schuetzt gegen einen manipulierten/umgeleiteten
`upload_url`-Wert in der Graph-API-Antwort. Der eigentliche Transfer-Mechanismus (synchroner
Binary-Stream-Upload) bleibt unangetastet, nur die neue Host-Pruefung ist neu.

Koeder-Test ergaenzt (`rejects a reel upload_url that does not point at the rupload host`):
simuliert eine Start-Response mit `upload_url` auf `attacker.example.com`, prueft dass die
Exception geworfen UND dass NIE ein Request an den fremden Host geht (`Http::assertNotSent`).
Volle Testsuite danach: 4739 passed (0 failed), inkl. dieses neuen Tests.
