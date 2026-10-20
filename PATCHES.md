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

### Patch 5 · story-photo-fit  (TPS-01, Backend-Teil B1)

Story-Fotos auf Instagram und Facebook werden auf 9:16 (1080 x 1920 JPEG) gebracht, statt vom
Editor geblockt oder als Querformat veröffentlicht zu werden. Der Nutzer wählt pro Story-Plattform
im Meta-Feld `story_fit` einen Modus: `center` (Standard, auch ohne Auswahl oder bei unbekanntem
Wert), `smart` (Gemini Vision schlägt den Ausschnitt vor, jeder Fehler wird zu `center`),
`manual` (Rahmen `story_crop`, normalisiert 0..1, ungültig wird zu `center`) und `fit` (ganzes
Foto auf Unschärfe-Hintergrund). Facebook rendert danach wie bisher das Story-Video mit KI-Musik,
aber aus dem fertigen 1080 x 1920 Bild. Upstream löst Foto-Stories seit #374 anders (`photo_stories`
ohne Musik), ein Merge würde unseren Musik-Weg brechen, darum dieser schmale eigene Patch.

Schadensklasse kundendaten: PlayCraft veröffentlicht über dieselbe Instanz. Feed-Posts, Reels und
Videos sind unverändert. Story-Fotos ohne `story_fit` werden jetzt mittig auf 9:16 zugeschnitten
(Instagram vorher: ganzes Foto auf Unschärfe-Hintergrund, siehe Verhaltensänderungen (a); Facebook
vorher: unbeschnittenes Querformat-Video). `Platform::Instagram` und `Platform::InstagramFacebook` nutzen dieselbe
`InstagramPublisher`-Instanz, das Fitting gilt für beide Wege gleich.

- **Marker**: `PATCH:story-photo-fit` als Kommentar an jeder Berührungsstelle.
- **Dateien** (alle mit Marker):
  - `app/Services/Media/MediaOptimizer.php` (`cropToRect`, `coverToSize`, `RECT_RATIO_TOLERANCE`)
  - `app/Services/Media/StoryImageFitter.php` (neu), `app/Services/Media/StoryCropSuggester.php` (neu,
    Gemini, wirft nie, Ergebnis pro Bildinhalt 7 Tage gecacht)
  - `resources/views/prompts/story_crop/suggest.blade.php` (neu, Prompt als Blade)
  - `app/Services/Media/ImageToVideoConverter.php` (Scale/Pad-Filter auf 1080 x 1920)
  - `app/Services/Social/Concerns/CropsImageForAspectRatio.php` (`prepareStoryImageUrl`)
  - `app/Services/Social/InstagramPublisher.php` (Foto-Zweig von `publishStory`)
  - `app/Services/Social/FacebookPublisher.php` (`convertImageToStoryVideo`, Diff bewusst klein
    gehalten, weil diese Datei bei Upstream-Merges Konflikte erzeugt)
  - `app/Enums/PostPlatform/ContentType.php` (`autoFitsImage()` auch für `FacebookStory`)
  - `app/Support/PostPlatformMetaRules.php` (`story_fit`, `story_crop`, einzige Stelle für Meta-Regeln)
  - Editor (E1, Frontend, alle mit Marker): `resources/js/lib/imageCrop.ts` (Parameter `aspect`, Normalisierung),
    `resources/js/components/ImageCropperDialog.vue` (Props `aspect`, `emitRectOnly`, `initialRect`; `PhotoUpload` bleibt unverändert),
    `resources/js/components/posts/editor/StoryFitSettings.vue` (neu), `FacebookSettings.vue`, `InstagramSettings.vue`,
    `MediaRulesWarning.vue` (Info-Hinweis je Modus statt Fehler bei Story-Fotos), `resources/js/composables/useMedia.ts`
    (`isStoryPhoto`), `resources/js/composables/usePostCompliance.ts` (klarer Text für Story-Videos), `lang/*/posts.php`
    (`posts.story_fit.*` in allen 16 Locales). Videos, Feed-Posts und Reels sind unverändert: Die Aufhebung der Sperre für
    Story-Fotos kommt weiter allein aus `autoFitsImage` des Backends.
  - **Rahmen an Foto gebunden** (Nacharbeit E1): das Meta-Feld `story_crop_media_id` (Regel in `PostPlatformMetaRules`)
    hält die ID des Fotos, auf dem `story_crop` gezogen wurde. `StoryImageFitter::boundCrop()` wendet den Rahmen bei
    `manual` nur an, wenn die ID dem ersten Foto der Plattform-Auswahl entspricht, sonst Mitte (Log::info, nie Fehler),
    für Facebook und Instagram. `story_crop` wirkt also nur zusammen mit passender `story_crop_media_id`; API und MCP
    müssen beide Felder senden (die MCP-Tool-Beschreibung sagt es). Editor: `resources/js/lib/imageCrop.ts`
    (`boundStoryCrop`) zeigt "Ausschnitt gespeichert" nur bei passender ID.
  - **Validierung**: `story_crop_media_id` ist optional (`sometimes|nullable|string|max:64`), `story_crop` ohne ID wird beim
    Speichern NICHT abgelehnt. Grund: der Editor schickt das gespeicherte Meta bei jedem Autosave zurück, eine Pflichtregel
    würde Posts mit Rahmen ohne ID sperren (unsichtbarer 422, Änderungen gehen verloren). Ohne passende ID wird beim
    Veröffentlichen still mittig zugeschnitten (`boundCrop`, Log `media_id_missing` bzw. Abweichung).
  - **Bestandsdaten**: bereits gespeicherte `manual`-Posts ohne `story_crop_media_id` (aus der Zeit zwischen PR #25
    und diesem PR, beides vor dem Deploy noch nicht live) werden beim Veröffentlichen mittig zugeschnitten und lassen sich
    weiter speichern, es gibt keine Migration. API- und MCP-Clients sollten beide Felder senden, sonst wirkt der Rahmen nicht.
- **Verhaltensänderungen** (bewusst, Olli-Entscheid 08.10.2026):
  - (a) Instagram-Story-Fotos ohne `story_fit` werden jetzt mittig auf 9:16 zugeschnitten, statt als ganzes Foto
    auf Unschärfe-Hintergrund zu erscheinen (Standard bleibt `center`, kein Rückbau auf `fit`). Deshalb Deploy
    zusammen mit E1 (Editor). Bereits geplante oder per API/MCP erzeugte Instagram-Stories ändern ihr Aussehen.
    Facebook-Foto-Stories ohne `story_fit` werden ebenfalls mittig geschnitten, vorher gingen sie unbeschnitten raus.
  - (b) Modus `smart` sendet ein 768-px-Abbild des Kundenfotos an `generativelanguage.googleapis.com`
    (nur wenn `GEMINI_API_KEY` gesetzt ist).
- **Prüf-Grep nach jedem Upstream-Merge**: `grep -rl 'PATCH:story-photo-fit' app resources` muss mindestens
  10 Dateien liefern (die obige Liste), danach `vendor/bin/pest --filter=tps01`.
- **Bruchbedingung**: Upstream ändert `FacebookPublisher::convertImageToStoryVideo`, `InstagramPublisher::publishStory`
  oder `ContentType::autoFitsImage()` und der Merge läuft konfliktfrei durch. Dann Marker einzeln
  gegenprüfen, nicht auf einen Merge-Konflikt verlassen.
- **Tests**: alle Pest-Namen beginnen mit `tps01 ` (Leerzeichen, Filter `--filter=tps01`; nur die Tempfile-Präfixe
  haben Unterstriche). Abgedeckt: Fitter, Suggester, `cropToRect`, Facebook- und Instagram-Story,
  Meta-Regeln in API und MCP, Scale/Pad, `autoFitsImage`, Tempfile-Aufräumen.
- **Server**: das Produktions-Image hat nur GD (kein Imagick), die GD-Pfade sind getestet. Der
  Gemini-Key kommt aus `services.gemini.api_key`, ohne Key bleibt `smart` bei `center`.
- **Stand**: noch nicht deployed (Editor-Teil E1 folgt, Deploy ist ein eigenes Olli-Gate).

### Patch 6 · ai-generate-preview  (AIG-01 + AIG-02, 08.10.2026)

"Generate with AI" zeigt keine Vorschau

Symptom (live, social.madevisible.io): Job `StreamPostContent` laeuft durch und Tokens werden
verbucht, der Dialog bleibt aber bei "..." und endet bei "Try again". Broadcasting selbst ist
gesund: Event-Namen (`text_delta`, `stream_end`, `error`) stimmen mit `StreamEvent::type()` aus
laravel/ai ueberein, und der Dialog abonniert den Kanal vor dem POST (#269).

Ursache: Der Streamer nutzte das Prompt-Template des strukturierten Generators ("Output format: a
JSON object ...") und der Dialog zeigte die Vorschau erst, wenn der gesamte Stream per
`JSON.parse` lesbar war. Ohne Structured-Output-Modus ist das ein Wunsch an das Modell: jede
Code-Fence oder jeder Einleitungssatz laesst den Parse scheitern, die Vorschau bleibt leer
obwohl der Text erzeugt wurde. Das ist aus dem Code und den Messwerten abgeleitet, der rohe
Stream-Text der Live-Instanz wurde nicht mitgeschnitten (kein Schreibzugriff dort).

Fix: `PostContentStreamer` uebergibt `plain_text`, das Template verlangt dann nur den Beitragstext
(`@elseif(!empty($plain_text))`), der Dialog zeigt den Stream direkt (echtes Live-Streaming).
`PostContentGenerator` und die Bild-Templates bleiben unveraendert. Marker `PATCH:aig-01` in
`generator.blade.php`, `PostContentStreamer.php`, `AiGenerateDialog.vue`.
Test: `tests/Feature/Ai/PostContentStreamerTest.php` (vorher rot).

AIG-02 (Fehlerpfad, gleicher PR): laravel/ai broadcastet ein Provider-Fehlerevent unter dem
Fehlercode (`unknown_error`, `overloaded_error`, `stream_failed` ...), nicht unter `error`; der
Dialog hoerte nur auf `.error`. Eine Exception im Job (HTTP 4xx/5xx, Timeout, fehlender Key)
sendete gar nichts. Jetzt sendet `StreamPostContent` ein festes Event `error` (Anonymous Event,
`Broadcast::on(...)->as('error')`, ohne Provider-Text) bei Error-Event im Stream, im `catch` und in
`failed()`. Am Stream-Ende loggt der Job `PostContentGenerator stream ended` nur mit Laengen
(`delta_count`, `char_count`, `starts_with_fence`, `starts_with_brace`, `generation_id`), nie mit
Inhalt. Frontend: `useAiStream` startet die Frist erst nach erfolgreichem POST (`start()`, vom Dialog
aufgerufen): bis zum ersten Event 120 s (`AI_STREAM_FIRST_EVENT_TIMEOUT_MS`), danach 60 s ohne
Fortschritt (`AI_STREAM_IDLE_TIMEOUT_MS`; `stream_start`, `reasoning_start`, `reasoning_delta` und
`text_delta` setzen die Frist neu). Bei Ablauf wird der Status `failed` und der Channel verlassen,
spaete Events werden ignoriert, ein bereits `completed`er Stream wird nie ueberschrieben. Ein
erfolgreich beendeter leerer Stream wird `failed` (Texte `posts.ai.generate.errors.empty` und
`.timeout`, alle 16 Locales). Retry ist bei `failed` sichtbar.

- **Marker**: `PATCH:aig-01`.
- **Dateien**: `app/Ai/Agents/PostContentStreamer.php`, `resources/views/prompts/post_content/generator.blade.php`,
  `app/Jobs/Ai/StreamPostContent.php`, `resources/js/composables/echo/useAiStream.ts`,
  `resources/js/components/posts/ai/AiGenerateDialog.vue`, `lang/*/posts.php` (`posts.ai.generate.errors.timeout|empty`).
- **Prüf-Grep nach jedem Upstream-Merge**: `grep -rl 'PATCH:aig-01' app resources` muss 5 Dateien liefern,
  danach `vendor/bin/pest tests/Feature/Ai --filter=aig02`.
- **Bruchbedingung**: Upstream aendert `PostContentStreamer`, `StreamPostContent` oder `useAiStream`
  und der Merge laeuft konfliktfrei durch: Marker einzeln gegenpruefen.
- **Tests**: `tests/Feature/Ai/PostContentStreamerTest.php` (vorher rot), `tests/Feature/Ai/StreamPostContentJobTest.php` (Namen mit `aig02 `, Filter `--filter=aig02`).
- **Live-Beweis nach Deploy**: Log `PostContentGenerator stream ended` pruefen (beginnt der Text mit ``` oder {?).
- **Stand**: nicht deployed (Olli-Gate).

### Patch 7 · unpublish-stories  (UNP-01, 08.10.2026)

Unpublish fuer Facebook-Stories ausgrauen

Ausloeser: Facebook-Stories lassen sich per Meta-API nicht loeschen
(`ContentType::FacebookStory->supportsDelete()` ist false, `UnpublishPost` legt sie in `unsupported`),
trotzdem bot die Published-Seite die Aktion an und der Nutzer hielt die Story fuer entfernt. Die
Seite prueft vorher nur eine hartkodierte Plattformliste (`tiktok`, `instagram`) im Frontend, das
kannte weder Story noch Content-Type.

Fix: eine einzige Wahrheit auf dem Server. `UnpublishPost::deletePublisherFor(PostPlatform)` buendelt
`supportsDelete()`, die Instagram-Direct-Login-Ausnahme und `method_exists(..., 'delete')`;
`UnpublishPost::execute()` nutzt sie (Verhalten und Buckets unveraendert),
`PostPlatform::canBeUnpublished()` delegiert dorthin. `Post::unpublishAvailability()` liefert aus den
veroeffentlichten Zeilen (`platform_post_id` gesetzt) `can_unpublish` und
`unpublish_blocked_reason` (`facebook_story` wenn alle veroeffentlichten Zeilen Stories sind,
sonst `unsupported`). `PostController::index` haengt beides an jeden Post der Liste. `posts/Index.vue`
liest nur noch diese Flags: bei `can_unpublish=false` ist der Menueeintrag `disabled` (Radix setzt
`aria-disabled`/`data-disabled`) und ein sichtbarer Hinweistext (kein Tooltip, tastaturfaehig,
`aria-describedby`) erklaert warum. Gemischte Posts (z.B. Story plus Reel) bleiben aktiv, das
bestehende `unpublish_unsupported`-Flash bleibt. Delete des Posts ist unberuehrt. Neuer Text
`posts.actions.unpublish_unsupported_story` in allen 16 Locales.

- **Marker**: `PATCH:unp-01`.
- **Scope**: die Verfuegbarkeit zaehlt ALLE veroeffentlichten Zeilen (auch deaktivierte, eine Zusatzabfrage
  pro Seite), weil `UnpublishPost::execute()` sie ebenfalls entfernt.
- **Dateien**: `app/Actions/Post/UnpublishPost.php`, `app/Models/PostPlatform.php`, `app/Models/Post.php`,
  `app/Http/Controllers/App/PostController.php`, `resources/js/pages/posts/Index.vue`, `lang/*/posts.php`
  (`posts.actions.unpublish_unsupported_story`).
- **Pruef-Grep nach jedem Upstream-Merge**: `grep -rl 'PATCH:unp-01' app resources` muss 5 Dateien
  liefern, danach `vendor/bin/pest --filter=unp01`.
- **Bruchbedingung**: Upstream aendert `UnpublishPost::execute()`, `PostController::index` oder die
  Aktionsleiste in `posts/Index.vue` und der Merge laeuft konfliktfrei durch: Marker einzeln
  gegenpruefen. Besonders: bringt Upstream wieder eine Plattformliste im Frontend oder prueft
  `supportsDelete()` direkt in `execute()`, driften Anzeige und Aktion auseinander.
- **Tests**: `tests/Feature/Actions/Post/UnpublishAvailabilityTest.php` und
  `tests/Browser/PostUnpublishTest.php` (Namen mit `unp01 `, Filter `--filter=unp01`).
- **Browser-Test repariert (UNP-02, 08.10.2026)**: `unpublishing a post removes it from a delete-capable platform ...`
  war rot, weil `seedUnpublishPost()` den Facebook-Account mit `scopes => []` anlegte. `UnpublishPost`
  meldet das als `failed` ("Missing permissions: pages_manage_posts"), der Post blieb Published. Kein App-Fehler,
  der Seed traegt jetzt `requiredDeleteScopes()`. Zusatz in `UnpublishAvailabilityTest`: `instagram-facebook`
  ist unpublishbar, ein reiner Instagram-Direct-Post liefert `can_unpublish=false` mit Grund `unsupported`.
- **Stand**: nicht deployed (Olli-Gate).

### Patch 8 · tiktok-disclosure-label  (TTL-01, 08.10.2026)

Fuer das TikTok-Direct-Post-Audit steht auf den Content Sharing Guidelines
(developers.tiktok.com/docs/en/content-sharing-guidelines, abgerufen 08.10.2026) unter der
Ueberschrift "Content Disclosure Setting" der Satz "Indicate whether this content promotes yourself,
a brand, product or service, with this feature turned off by default." Das ist die BESCHREIBUNG der
Einstellung, kein vorgeschriebener Schalter-Text. Darum bleibt das Checkbox-Label
`posts.form.tiktok.disclose` auf dem Upstream-Wert ("Disclose video content"), und der Satz (ohne
den Nachsatz, der Schalter ist ohnehin standardmaessig aus) erscheint als eigener Absatz
(`data-testid="tiktok-disclose-description"`) direkt unter dem Label, ueber `disclose_hint`.
Neuer Key `posts.form.tiktok.disclose_description` in allen 16 Locales, direkt vor `disclose_hint`.

- **Marker**: `PATCH:tiktok-disclosure-label` (Kommentar in `TikTokSettings.vue`, Testdatei).
- **Dateien**: `lang/*/posts.php` (neuer Key `disclose_description`), `resources/js/components/posts/editor/TikTokSettings.vue` (zusaetzlicher Absatz).
- **Pruef-Grep nach jedem Upstream-Merge**: `grep -c "'disclose_description' =>" lang/*/posts.php` muss
  in allen 16 Dateien 1 liefern, `grep -c "tiktok-disclose-description" resources/js/components/posts/editor/TikTokSettings.vue`
  muss 1 liefern, danach `vendor/bin/pest tests/Unit/TikTokDisclosureLabelTest.php`.
- **Bruchbedingung**: Upstream aendert `lang/*/posts.php` oder `TikTokSettings.vue` (Absatz faellt weg),
  ausserdem vor jeder neuen TikTok-Audit-Einreichung gegen die aktuelle Guidelines-Seite abgleichen.
- **Tests**: `tests/Unit/TikTokDisclosureLabelTest.php` (Namen mit `ttl01 `, exakter Wortlaut je Locale).
- **Stand**: nicht deployed (Olli-Gate).

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

### Patch 9 · tiktok-refresh-invalid-grant  (TTR-02, 08.10.2026)

Backport aus Upstream trypostit/trypost#390 (nur der TikTok-Refresh-Teil, Commit "Fix the
production errors reported in Nightwatch"). TikTok beantwortet einen toten Refresh-Token mit einem
Fehler-Body, oft mit HTTP 200. Der Fork las das als "kein access_token" und damit als
`PlatformUnavailableException` (vorübergehend): das Konto blieb `connected`, obwohl das
Access-Token seit 24.09.2026 abgelaufen war, ohne Mail und ohne Reconnect in der UI.

Jetzt: `invalid_grant` im Body (bei 200 oder 4xx) wirft `TokenExpiredException` und lässt
`RefreshSocialToken` das Konto über `markAsTokenExpired()` auf `token_expired` setzen (Mail, Reconnect).
Jeder andere Fehlercode bleibt vorübergehend, die Meldung nennt jetzt den Code
(`TikTok refresh returned <code>: <description>`).

- **Datei**: `app/Services/Social/ConnectionVerifier.php` (`refreshTikTokToken()`,
  `throwIfDeadTikTokRefresh()`, Konstante `TIKTOK_DEAD_REFRESH_ERRORS`).
- **Nicht-invalid_grant-4xx** (inkl. invalid_client, Nicht-JSON, verschachteltes error-Objekt) sind
  jetzt vorübergehend statt `token_expired`.
- **Race-Schutz**: `RefreshSocialToken` setzt das Konto nach invalid_grant erst auf `token_expired`,
  wenn auch das Access-Token abgelehnt wird (`accessTokenStillWorks`, Schutz bei Single-Use-Refresh-Tokens).
- **tokenFrom/rotatedTokenFrom** nehmen `mixed` wie Upstream #390 (Nightwatch-Produktionsfehler):
  ein Skalar als Erfolgs-Body wirft `PlatformUnavailableException` statt `TypeError`.
- **Merge-Hinweis**: beim nächsten Upstream-Merge entfällt der Patch, wenn #390 vollständig
  übernommen wird (Code ist dort wortgleich, auch `tokenFrom`).
- **Tests**: `ConnectionVerifierTest` und `RefreshSocialTokenTest` (Abschnitte "TTR-02").

### Patch 10 · story-motion  (TPS-SM-01, 20.10.2026)

Facebook-Story-Fotos werden für Workspaces mit eingeschalteten Story-Videos (seit Patch 11 eine Einstellung pro Workspace) nicht mehr als stehendes Bild, sondern als
kurzes Kamerafahrt-Video veröffentlicht, optional mit animierter Headline (Wort für Wort). Gerendert
von einem eigenen internen Container `story-render` (HyperFrames 0.8.174, Chromium, ffmpeg). Jeder
Fehler, jede Zeitüberschreitung und ein belegter Renderer führen zurück auf das bisherige Standbild
(`ImageToVideoConverter::convert()`), kein Publish scheitert am neuen Pfad. Default: aus (Env-Hauptschalter und Workspace-Einstellung).

- **Marker**: `PATCH:story-motion` (`grep -rn "PATCH:story-motion" app config lang resources`).
- **Render-Dienst (neu, `docker/story-render/`)**: `server.mjs`, `lib/`, `template/` (Komposition,
  Noto Serif 700 als woff2 mit OFL-Text, lokal), `Dockerfile`, `package.json` plus Lockfile
  (`hyperframes` und `gsap` gepinnt, `npm ci` beim Build, nie zur Laufzeit geladen), Tests unter `test/`
  (`cd docker/story-render && node --test`, 151 Tests mit Patch 11). `compose.prod.yaml` (Upstream-Selfhost) bleibt unberührt.
- **Laravel**: `app/Services/Media/StoryMotionRenderer.php` (Aktivierung, HTTP, Zeitbudget 300 s ab
  Publish-Start, ffprobe-Prüfung der Render-Ausgabe), `StoryVideoInspector.php` (ffprobe-Wächter für
  das Endvideo: ein h264/yuv420p-1080x1920-30-fps-Videostream, genau ein AAC-Stream, Dauer ±0,5 s,
  moov vorn), `ImageToVideoConverter::addAudioTrack()` (Audio-Mux mit `-c:v copy`),
  `FacebookPublisher` (`publishStory()`, `convertImageToStoryVideo()`, `renderStoryMotionVideo()`),
  `PostPlatformMetaRules` (`story_headline`, max 80), Shared Prop `facebookStoryMotionEnabled`
  (`HandleInertiaRequests`), Feld in `FacebookSettings.vue`, Label in allen 16 `lang/*/posts.php`.
- **Reihenfolge nur mit Motion**: Nur für einen Workspace mit aktiver Motion (Env-Schalter, URL, Token, Workspace-Einstellung)
  läuft die Foto-Konvertierung (Download, Fit, Musik, Render, Mux) VOR dem Facebook-`start`-Call, damit ein
  langer Render keine offene Upload-Sitzung hält. Für alle anderen Workspaces bleibt der Ablauf wie auf
  `origin/main`: `start`, dann Konvertierung, dann Upload. Bei Motion entstehen Musik und Render also auch
  dann, wenn `start` später abgelehnt wird. Ein `try/finally` umschließt Konvertierung und alle
  Upload-Phasen, die Temp-Datei wird auf jedem Pfad entfernt. Video-Stories bleiben unverändert.
- **Env (Reihenfolge ENABLED, TOKEN, dann URL, TIMEOUT)**: `FACEBOOK_STORY_MOTION_ENABLED`
  (false, Hauptschalter der Installation), `FACEBOOK_STORY_MOTION_TOKEN` (gleicher Wert wie `STORY_RENDER_TOKEN` im
  Dienst), `FACEBOOK_STORY_MOTION_URL` (`http://story-render:3000`), `FACEBOOK_STORY_MOTION_TIMEOUT` (255 =
  Dienst-Timeout 240 plus 15, im Code auf 10 bis 255 begrenzt). Aktiv nur bei Hauptschalter UND URL UND Token
  UND Workspace-Einstellung (Patch 11). Die frühere Env-Allowlist `FACEBOOK_STORY_MOTION_WORKSPACES` entfällt
  mit Patch 11. Neue Env-Variablen in `/opt/trypost/compose.yml` (bzw. dort, wo die bestehenden
  `FACEBOOK_*` stehen) nachtragen, siehe Repo-CLAUDE.md "Production Deploy (web02)".
- **Produktions-Service (Compose auf web02, nicht im Repo)**: Build aus `./src/docker/story-render`,
  `container_name: trypost-story-render`, eigenes Netz mit `internal: true` (der `app`-Service hängt an
  beiden Netzen), kein veröffentlichter Port, `init: true`, `read_only: true`, `tmpfs: /tmp`,
  `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`, `user: node`, `pids_limit: 256`,
  `mem_limit: 3g`, `memswap_limit: 3g`, `cpus: 2`, `shm_size: 1g`, `restart: unless-stopped`
  (dokumentierte Produktions-Ausnahme), `STORY_RENDER_TOKEN` per Env, Label `owner=trypost`.
- **Lizenzen**: hyperframes Apache-2.0, Noto Serif SIL OFL 1.1 (Text liegt bei den Fontdateien),
  fontkit und puppeteer-core MIT bzw. Apache-2.0. GSAP steht unter der "Standard no charge license"
  (gsap.com/standard-license, kommerzielle Nutzung frei, verboten nur in konkurrierenden visuellen
  Animations-Buildern): GSAP liegt NICHT im Repo, nur als gepinnte npm-Abhängigkeit, die beim Image-Build
  installiert wird. Die AGPL-Veröffentlichung des Forks enthält also keinen GSAP-Code.
- **Akzeptiertes Restrisiko**: HyperFrames startet Chromium fest mit `--no-sandbox` (im Container ohne
  Capabilities nicht anders möglich). Das Foto kommt von Kunden und wird vom Chromium-Bilddecoder ohne
  Sandbox dekodiert. Gegenmaßnahmen: nur JPEG/PNG nach Magic Bytes, höchstens 8 MB und 4096 x 4096,
  Nutzer `node`, `cap_drop: ALL`, `no-new-privileges`, `read_only`, internes Netz ohne Egress, kein
  veröffentlichter Port, Token. Debian-Chromium-Updates kommen mit jedem Rebuild des Images (Basisimage
  per Digest im `Dockerfile` gepinnt; Chromium im Smoke 155.0.8059.79, im Deploy-Bericht notieren).
- **Wartungspunkt Digest**: Der Digest im `Dockerfile` friert Node und die Debian-Basisschicht ein, sie ändern sich
  nur durch bewusstes Anheben des Digests (z. B. bei jedem Node-22-Sicherheitsrelease, Digest per
  `docker buildx imagetools inspect node:22-bookworm-slim` holen). Chromium und ffmpeg kommen bei jedem Rebuild
  frisch aus Debian bookworm. Nach dem Build `docker exec <dienst> chromium --version` im Deploy-Bericht notieren.
- **Betrieb des Dienstes**: Erreichbarkeit nur über das interne Compose-Netz (`internal: true`, kein `ports:`,
  kein `pid: host`, weil der Orphan-Sweep den eigenen PID-Namespace voraussetzt). `server.maxConnections` ist 32. `STORY_RENDER_TIMEOUT_SECONDS` leer oder ungültig fällt auf 240 zurück, sonst 10 bis 240 (am besten gar nicht setzen). Der Slot gehört dem Auftrag, der ihn belegt hat.
  stderr des Renders wird nicht geloggt (es kann Headline-Text zitieren), nur Exit-Code und Größe; zum
  Debuggen `STORY_RENDER_LOG_STDERR=1` setzen. `tmpfs: /tmp` mit `size=4g` setzen (Deckel, keine Belegung): HyperFrames bricht ab, wenn der Arbeitsordner 1 GB oder weniger frei hat (Meldung "Low disk space ... 1.0 GB free"), mit `size=1500m` oder kleiner wäre der Dienst auf web02 unbrauchbar.
- **Merge-Hinweis**: Beim Upstream-Merge `grep -rn "PATCH:story-motion"` gegenprüfen. Upstream kennt
  weder `docker/story-render/` noch `story_motion`.
- **Tests**: `StoryMotionRendererTest`, `StoryVideoInspectorTest`, `ImageToVideoConverterTest`
  (Abschnitt story-motion), `FacebookPublisherTest` (Abschnitt story-motion), `PostApiPlatformMetaTest`,
  `PostPlatformMetaToolTest`; Render-Dienst `docker/story-render/test/`.

#### Deployed 20.10.2026 (Dark Launch, Workspace-Liste leer; die Liste wurde mit Patch 11 durch die Workspace-Einstellung ersetzt)

PR #33 ist gemergt (squash `e0b338be96f0dd9ee539bbced2a73d1c0324c21f`) und am 20.10.2026 auf web02 deployed.
Es läuft als Dark Launch: Flag an, Workspace-Liste leer, also ändert sich für keinen Workspace etwas.

- **Compose** (`/opt/trypost/compose.yml`, nicht im Repo): Service `story-render`, `container_name:
  trypost-story-render`, Build aus `./src/docker/story-render`, Netz `story-render-internal` mit `internal: true`
  (der `app`-Service hängt am `default`-Netz und am internen Netz), `read_only: true`, `tmpfs: /tmp` mit
  `size=4g`, `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`, `mem_limit: 3g`, `memswap_limit: 3g`,
  `cpus: 2`, `shm_size: 1g`, `pids_limit: 256`, `init: true`, `restart: unless-stopped` (dokumentierte
  Produktions-Ausnahme), keine Ports.
- **Env am `app`-Service**: `FACEBOOK_STORY_MOTION_ENABLED=true`, `FACEBOOK_STORY_MOTION_WORKSPACES=` (leer),
  `FACEBOOK_STORY_MOTION_TOKEN` gesetzt (gleicher Wert wie `STORY_RENDER_TOKEN` am Dienst, der Wert wird nie
  dokumentiert), `FACEBOOK_STORY_MOTION_URL=http://story-render:3000`, `FACEBOOK_STORY_MOTION_TIMEOUT=255`.
  Am Dienst sind `STORY_RENDER_LOG_STDERR` und `STORY_RENDER_TIMEOUT_SECONDS` im Betrieb NICHT gesetzt und
  bleiben es.
- **Rückweg**: Backup `/opt/trypost/compose.yml.bak-pre-story-motion-20261020024828`, Rückweg-Image
  `trypost:pre-story-motion-20261020024828`. Schnellster Weg: `FACEBOOK_STORY_MOTION_ENABLED=false` und
  `docker compose up -d --no-deps app` (Standbild wie vorher), den Dienst `story-render` stoppen.
- **Smoke auf web02**: 15-s-Render in 53 bis 78 s, Spitzen-RAM 1,19 GiB, Endvideo-Wächter ok, kein Egress aus
  dem Dienst (internes Netz).
- **Offen vor der Aktivierung (P4)**: ein Workspace schaltet Story-Videos erst nach Olli-Freigabe in seinen Einstellungen ein (Patch 11),
  vorher Beispielvideo zeigen. Zu klären sind die Kosten je Horizon-Wiederholung (Render und gegebenenfalls
  KI-Musik, auch wenn Facebook `start` ablehnt) und die Zahl gleichzeitiger Stories pro Slot (es rendert genau
  eine, weitere fallen auf das Standbild zurück).

### Patch 11 · story-motion-settings  (TPS-SM-02, 20.10.2026)

Story-Videos (Patch 10) werden zu Einstellungen pro Marke: Wer die Facebook-Foto-Stories als bewegtes
Video veröffentlichen will, schaltet das im Workspace unter Einstellungen, Brand, Story-Videos ein (Default
aus) und wählt Schrift, Bildeffekte, Effekt-Modus, Logo-Ebene, KI-Hinweis und Abschluss. Pro Story gibt es
zusätzlich Unterzeile, Badge und eine zweite Sprache. Die Env-Allowlist aus Patch 10 entfällt. Der Render-Dienst
bekommt dafür einen neuen Auftrag (multipart: `photo`, optional `logo`, `spec`), vier Effekte, fünf Schriften und
eine Spec-Validierung.

- **Marker**: `PATCH:story-motion-settings` (`grep -rn "PATCH:story-motion-settings" app config database lang resources routes`).
- **Aktivierung**: Env-Hauptschalter `FACEBOOK_STORY_MOTION_ENABLED` UND URL UND Token UND `story_motion_settings.enabled`
  des Workspace. Skip-Gründe `disabled` und `workspace_off` werden nicht geloggt, `no_url` und `no_token` mit einer Info-Zeile.
  Eine Shared Prop: `facebookStoryMotionEnabled` (zusätzlich Workspace an, steuert die Felder im Post-Editor). Den Abschnitt in den
  Brand-Einstellungen steuert `storyMotion.available` aus `WorkspaceController::brandSettings` (Env, URL, Token), keine zweite Quelle.
- **Migration**: genau eine, `2026_10_20_120000_add_story_motion_settings_to_workspaces_table.php`, eine nullable JSON-Spalte
  `story_motion_settings` auf `workspaces` (mit `down()`). NULL heißt Defaults, Default ist aus. Läuft beim Containerstart
  automatisch, darum vor dem Deploy ein `pg_dump` (siehe Plan, P7). Bei Rollback des Images bleibt die Spalte bestehen und ist
  für das alte Image harmlos.
- **Einstellungen** (`App\Services\Media\StoryMotionSettings`, liest defensiv und wirft nie): `enabled`, `font`
  (`inter`, `commissioner`, `comfortaa`, `noto-sans`, `noto-serif`, Default aus `brand_font` falls dort vorhanden, sonst Inter),
  `effects` (aus `drift`, `focus`, `snap`, `sparkle`, mindestens einer, Default `focus`), `mode` (`fixed`, `rotate`, `random`),
  `logo_layer`, `ai_notice` (an, Text bis 40), `ending` (an, Name bis 40, Zeile bis 60). Regeln an einer Stelle
  (`App\Support\StoryMotionSettingsRules`), genutzt vom Web-Endpunkt `PUT settings/workspace/story-video`
  (`WorkspaceController::updateStoryVideo`, `UpdateStoryVideoSettingsRequest`) und vom MCP-Tool `UpdateWorkspaceTool`
  (Schlüssel `story_motion_settings`, Merge nur der gesendeten Schlüssel, Rückgabe mit den Einstellungen). Der Abschluss lehnt
  Adressen und Telefonnummern ab (sechs oder mehr Ziffern, `+` plus Ziffer, `tel:`).
- **Effektwahl ohne Zustand** (`StoryMotionEffectPicker`): `fixed` der erste gewählte in der Reihenfolge drift, focus, snap, sparkle;
  `random` per `crc32(PostPlatform-ID) % n`; `rotate` per Anzahl veröffentlichter Facebook-Stories des Workspaces modulo n.
  Nichts wird gepinnt oder geschrieben, ein Retry wählt dasselbe, solange dazwischen nichts veröffentlicht wurde.
- **Akzentfarbe** (`App\Support\StoryMotionAccent`): aus `brand_color` (`#RGB`, `#RRGGBB`, `#RRGGBBAA` werden zu `#RRGGBB`), nur wenn
  WCAG-Kontrast mindestens 3:1 gegen `#4F6673` (oberer Textschleier höchstens 0,72 auf weißem Foto) UND gegen Schwarz, sonst Gold
  `#FDB31B`, sonst Weiß. Der Dienst prüft nur die Form (`\A#[0-9A-F]{6}\z`).
- **Logo** (Regel an einer Stelle: `App\Support\StoryMotionLogo`): nur JPEG und PNG (Magic Bytes), höchstens 2 MB und 2000 x 2000 px (aus `media.size` und `media.meta`), gelesen über
  `Storage::get`, keine URL. Sonst Logo weglassen, `logoLayer=false`, Log `logo_unusable` mit Grund. Der Upload-Pfad
  (`HasMedia::addMedia`) wandelt PNG in JPEG um, ein transparentes PNG wird dabei flach auf Weiß gesetzt.
  Seit Fix-Runde 1 (Olli-Entscheid 19.10.2026, "automatisch beim Hochladen verkleinern, gilt für alle Marken") verkleinert `HasMedia`
  ein Logo in der Collection `logo` beim Speichern proportional auf höchstens 2000 px und senkt bei Bedarf die JPEG-Qualität, bis es unter
  2 MB liegt (Upload im Controller, Website-Autofill über `LogoAttacher`, Direktweg `addMediaFromPath`). GIF wird nie verändert. Die
  Transparenz geht wie bisher bei der JPEG-Umwandlung verloren (flach auf Weiß), das Verkleinern ändert daran nichts. Die Brand-Seite meldet
  über `storyMotion.logoUsable` (aus den gespeicherten Werten, ohne Dateizugriff) ein früher gespeichertes Logo, das nicht passt.
- **Bewegung**: kräftige Werte nur mit Logo-Ebene UND KI-Hinweis (Ollis Grundstil-Bündel), sonst ruhig (Skalierung höchstens 1,02
  über dem Endbild, keine Neigung, keine Drift, Endbild gleich Foto). Tabelle und Deckungsinvariante in
  `docker/story-render/lib/motion.mjs`, getestet in `test/motion.test.mjs`. Endwerte: `focus` startet bei 1,03 (statt 1,02,
  wegen der Neigung), `snap` endet bei 1,04 mit 20 px Versatz.
- **Render-Dienst** (`docker/story-render/`): `lib/multipart.mjs` (Node-Bordmittel `Response.formData()`, höchstens 12 MB,
  3 Teile, Foto 8 MB, Logo 2 MB, Spec 8 KB), `lib/spec.mjs` (strenge Validierung, unbekannte Schlüssel abgewiesen, nichts aus der
  Anfrage wird in Namen oder Pfade übernommen), `lib/motion.mjs`, `lib/timeline.mjs`, `lib/text.mjs`, `lib/fonts.mjs`,
  `template/` (Vorlage, `fonts/`). Schriften: Fontsource 5.3.0, SIL OFL 1.1, Teilmengen latin, latin-ext, greek, cyrillic, je Familie
  ein eigener `font-family`-Name, OFL-Text je Familie. Quellen und SHA256 siehe unten. `package.json` und Lockfile des Dienstes sind
  unverändert.
- **Post-Editor**: `FacebookSettings.vue` zeigt Überschrift, Unterzeile, Badge, zweite Sprache (Überschrift, Unterzeile) und den
  Hinweis zur `*Akzent*`-Schreibweise nur bei `facebookStoryMotionEnabled`. Meta-Regeln `story_subline` (60), `story_badge` (12),
  `story_headline_alt` (80), `story_subline_alt` (60) nur in `PostPlatformMetaRules`.
- **Entfernte oder geänderte Zusicherungen** (Allowlist-Tests aus Patch 10): die Tests, die eine Workspace-Allowlist, den Skip-Grund
  `workspace_not_allowed` und die Env-Zeile `FACEBOOK_STORY_MOTION_WORKSPACES` festschrieben, sind durch eine Aktivierungsmatrix
  (Hauptschalter, URL, Token, Workspace-Einstellung) ersetzt. Der Header `X-Story-Headline-B64` und der Query-Parameter `duration`
  des Dienstes entfallen zugunsten der Spec.
- **Deploy (Olli-Gate, nicht Teil des Builds)**: Compose auf web02 braucht keine neue Env-Variable. `FACEBOOK_STORY_MOTION_WORKSPACES`
  kann aus `/opt/trypost/compose.yml` entfernt werden (wird nicht mehr gelesen). Image `trypost-story-render` UND `trypost` neu bauen,
  beide gemeinsam starten. Die erwartete Migration ist genau die oben genannte. Nach dem Start:
  `SELECT count(*) FROM workspaces WHERE story_motion_settings->>'enabled' = 'true'` muss 0 sein.
- **Fix-Runde 1 (Review R1, 20.10.2026)**: (1) Logo: Verkleinern beim Speichern (siehe Logo), Regel in `App\Support\StoryMotionLogo`, Feld
  `storyMotion.logoUsable` und Hinweis `settings.story_video.logo_unusable` auf der Brand-Seite. (2) Die Shared Prop `storyMotionAvailable`
  entfällt (die Brand-Seite nutzt `storyMotion.available`). (3) Glyphenfilter im Dienst: Abdeckung je Familie ist die Schnittmenge aus den
  Zeichen der Fontdatei und dem `unicode-range` ihrer `@font-face`-Regel in `template/fonts.css` (`lib/fonts.mjs`), damit der Filter kein Zeichen
  durchlässt, das der Browser aus dem Systemfont zeichnen würde (U+0300, U+0303, U+0309, U+0323, U+FFFF). U+0301 bleibt, es liegt im
  Bereich der kyrillischen Teilmenge. (4) Unterer Schleier: `template/story.js` setzt ihn so, dass seine volle Stärke 0,80 40 px über dem
  Abschlussblock beginnt und bis zum unteren Rand reicht (auf weißem Foto `#3B5564`, Goldzeile gegen den Schleier allein 4,3:1, mit Textschatten rund 4,6:1). (5) Kontaktsperre im Abschluss:
  ein Ziffernlauf aus lauter Jahresgruppen (vier Ziffern, 1900 bis 2099, wie "Seit 1998 2026") gilt nicht als Telefonnummer. (6) Standardtext des
  KI-Hinweises ist der Platzhalter der Form in der Sprache des Nutzers (`StoryMotionSettings::defaultAiNotice()`); Sprachen, deren Text keine der
  fünf Schriften zeichnen kann (Arabisch, Japanisch, Koreanisch, Chinesisch), fallen auf den englischen Text zurück.
- **Fix-Runde 2 (Review R2-Delta, 20.10.2026)**: (1) KI-Hinweis als Pflichtlabel: `ai_notice.text` muss in Laravel lateinische,
  griechische oder kyrillische Schrift sein und mindestens einen Buchstaben enthalten (`StoryMotionSettings::isDrawableNotice()`, geprüft in
  `StoryMotionSettingsRules::rules()` und `assertComplete()`, Meldung `settings.story_video.errors.notice_text_unsupported`). Der Dienst setzt
  zusätzlich `AI-generated image` ein, wenn der gesendete Hinweis nach dem Glyphenfilter keinen Buchstaben mehr hat, und loggt nur
  `{"event":"ai_notice_fallback","reason":"not_drawable"}` (`lib/spec.mjs`, `lib/app.mjs`). (2) Logo: vor dem Decodieren prüft `HasMedia` bei
  Collections mit Grenzen die Pixelzahl (`StoryMotionLogo::MAX_DECODE_PIXELS`, 40 Megapixel) und wirft `ImageTooLargeException`. Der Upload
  antwortet darauf mit 422 (`settings.story_video.errors.logo_too_many_pixels`), der Website-Autofill überspringt das Logo. Ein Bild, dessen Maße
  nicht lesbar sind, wird in solchen Collections nicht decodiert. `addMedia` und `addMediaFromPath` löschen bei `single`-Collections das alte
  Medium erst nach dem Speichern des neuen; `uploadLogo` und `LogoAttacher` rufen `clearMediaCollection` nicht mehr selbst. (3) Kontaktsperre:
  Trenner sind zusätzlich Komma, Unterstrich, Mittelpunkt, Halbgeviert- und Geviertstrich, ein Doppelpunkt nur zwischen Gruppen ab drei Ziffern.
  (4) `logo_unusable` nennt JPEG oder PNG als Format für den neuen Upload.
- **Schrift-Quellen** (`npm pack @fontsource/<name>@5.3.0`, nur die benötigten woff2-Dateien liegen im Repo):
  - `@fontsource/inter@5.3.0`, Gewicht 800, Tarball SHA256 `02034af8d41dcc67ac8eab88f642e129bb8a2e8922abf30229c32725f54d8fd6`
    - `inter-cyrillic-800-normal.woff2` `e84cb164b47aaad63db12da876c341c59f78b6b25f9069dcdea3c1f8ca8b8f9b`
    - `inter-greek-800-normal.woff2` `18a6a4a8e29310db74c01b9d85b30e708e0b1fd8d9a2d01a259b82fe4d112422`
    - `inter-latin-800-normal.woff2` `a7d0a50f15d389cad679238466bdb5fc9787aa0715719064ce25abaff042820d`
    - `inter-latin-ext-800-normal.woff2` `eade55593308b9e4916dd7f47826929ce3e40cad85a2b338d56e5fd1e7bf8e5e`
  - `@fontsource/commissioner@5.3.0`, Gewicht 800, Tarball SHA256 `c532c0c5b6bb118c91e31d549d9f556655706b3285e2dcfe9d393efe44764d9a`
    - `commissioner-cyrillic-800-normal.woff2` `a43485eea576dff1a2d085944e71b3823de83250ab486a9dfbc020607d7fbc1e`
    - `commissioner-greek-800-normal.woff2` `28acf78f0eb887e06d387bbd9c169c8fd9dcf22348e20ff25b656d95f2d95d20`
    - `commissioner-latin-800-normal.woff2` `451f8930780d8d8c183c3b7f854a465b9730b3ddf07f2c971a165d38d67365d4`
    - `commissioner-latin-ext-800-normal.woff2` `cbb0fb46710f6b9372b676a704c6a18ba0b2d6a93973531dc1b84d5ec1b4ee56`
  - `@fontsource/comfortaa@5.3.0`, Gewicht 700, Tarball SHA256 `5c82d14fe6efc746213748a2835b7aa7b6e07409f1611eaee52eb25e0bdfcca6`
    - `comfortaa-cyrillic-700-normal.woff2` `52a4b41d6e1704b770c77ecdd2b3890b7b3dc9da7fd32e731e8607b6f29b1539`
    - `comfortaa-greek-700-normal.woff2` `1cd07fa22f1c8e21d5d29e8dd2dbbc2691d4ad5fc4084c76ca15c10562f7dea0`
    - `comfortaa-latin-700-normal.woff2` `ed9f328cf6aaa4c14dfb464d215e8a0d8d56b675a72fb601b819e95a1cb4e45d`
    - `comfortaa-latin-ext-700-normal.woff2` `bd890b939d24db500fc5c52da95d57ae4d94fa52dd3053a4b6cb6ea61c051b73`
  - `@fontsource/noto-sans@5.3.0`, Gewicht 700, Tarball SHA256 `f62f8ed40e378f15134e32bed2fd5718d81e9ec7a5603d1022d7b385e2297abc`
    - `noto-sans-cyrillic-700-normal.woff2` `0980b97224d9130cf3f651c8ad15b9e0b1eb361d31c71259363ec2bbd5f70750`
    - `noto-sans-greek-700-normal.woff2` `453c49c9043e04c1103ed1228a1d8d96750c858e08d6b204119d7a16b7642246`
    - `noto-sans-latin-700-normal.woff2` `e77bfe1db912f687b0319b60de158cfada67f89c8ee4f8e2bd6020f970accbfb`
    - `noto-sans-latin-ext-700-normal.woff2` `c0cd431266a7ed82d6f0dfe281e38a883c28a03eec1fdac308cc2f7c0ea39c3b`
  - `@fontsource/noto-serif@5.3.0`, Gewicht 700, Tarball SHA256 `846d219c2c9c668ebe2b5ca0e76c403a35f847d83c4c441c107f9bdc152830df`
    - `noto-serif-cyrillic-700-normal.woff2` `1dd6e758015cc8e424c0d57eabb94addf4adcba14c67ecdfd3b6386fc88295d0`
    - `noto-serif-greek-700-normal.woff2` `bd24b899d9fe3955fed1c9b0ffa1d12aca02db45474fe906735456430a2efd36`
    - `noto-serif-latin-700-normal.woff2` `0d0b440760c93e6fec440ad438d10cea6c70367a0f5ecfcfa15cef0a85c67093`
    - `noto-serif-latin-ext-700-normal.woff2` `52c9361ebb521542e71e5a11aa5683ab3bbd8df5428888e95786478bb1377cba`
- **Tests**: Pest `StoryMotionSettingsTest`, `StoryMotionSpecTest`, `StoryMotionAccentTest`, `StoryMotionActivationTest`,
  `StoryMotionEffectTest`, `StoryVideoSettingsTest`, `StoryMotionRendererTest`, `FacebookPublisherTest` (Abschnitt story-motion);
  Node `cd docker/story-render && node --test`.
