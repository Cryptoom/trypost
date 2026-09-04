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

### Patch 3 · sidebar-referral-discord-entfernung

Entfernt die zwei untersten Bottom-Nav-Eintraege der Sidebar ("Earn 30% referral" und "Discord
community"), auf Ollis ausdruecklichen Wunsch (das TryPost-eigene Affiliate-/Community-Programm
soll im Digital-Mind-Agency-Weissabel-Kontext nicht auftauchen). Entfernt zugleich die dadurch
unbenutzten Icon-Imports.

- **Datei**: `resources/js/components/AppSidebar.vue`.
- **Marker-Strings zum Wiedererkennen nach `git merge upstream/main`**: die Imports
  `IconBrandDiscord` und `IconGift` (aktuell in der Fork-Fassung NICHT mehr im
  `@tabler/icons-vue`-Import-Block von `AppSidebar.vue` vorhanden) sowie die beiden
  Objekt-Eintraege mit `href: 'https://affiliates.trypost.it/'` und
  `href: 'https://trypost.it/discord'` im `bottomNavItems`-Computed (in der Fork-Fassung
  entfernt). Der Docs-Link (`https://docs.trypost.it`) und `IconAffiliate` bleiben unveraendert
  bestehen und sind NICHT Teil dieses Patches.
- **Bruchbedingung**: Upstream ergaenzt in `bottomNavItems` neue Eintraege oder aendert die
  Struktur des Arrays (z.B. fuegt selbst wieder einen Referral- oder Community-Link hinzu) und
  `git merge upstream/main` laeuft konfliktfrei durch, weil der Fork die betroffenen Zeilen
  bereits geloescht hat und Git keinen Konflikt sieht. Ein solcher Merge kann den
  Referral-/Discord-Link stillschweigend zurueckbringen. Nach jedem Merge pruefen:
  `grep -n "trypost.it/discord\|affiliates.trypost.it" resources/js/components/AppSidebar.vue`
  muss leer bleiben.

### Patch 4 · madevisible-legal-footer-links

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
- **Deployed**: <Datum nach Merge + `docker compose up -d --build` auf web02 nachtragen>.

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
