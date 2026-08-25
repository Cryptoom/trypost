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

Keine. Diese Datei ist bewusst als leeres Geruest angelegt (Olli-Entscheidung 25.08.2026:
"Fork jetzt anlegen, Infrastruktur vorbereiten"), damit ein spaeterer Patch (z.B. der
`is_aigc`-Composer-UI-Toggle aus dem TryPost-Phase-4-Planungs-Pass, falls das GitHub-Issue
nicht zeitnah beantwortet wird) sofort in der etablierten Struktur landet statt einer neuen.

## Wie ein neuer Patch hier reinkommt

1. Aenderung im Fork machen, committen, Marker-String im Commit-Message + hier dokumentieren
   (Datei, Zeile/Funktion, WARUM, welcher Marker-String das Wiedererkennen nach einem Merge
   erlaubt).
2. `web02`-Deploy: `/opt/trypost` laeuft als lokaler Build aus Git-Checkout (Update-Klasse C,
   siehe `~/.claude/CLAUDE.md` Docker-Tabelle), NICHT das published Image. Fork-Checkout auf
   dem Server auf `Cryptoom/trypost` umstellen sobald der erste Patch aktiv wird (aktuell laeuft
   der Server-Checkout noch gegen upstream, unveraendert).
3. Nach jedem `git merge upstream/main`: alle Marker-Strings unten gegenpruefen, dieser
   Abschnitt fasst dann "Stand nach Merge <datum>" analog zum whatsapp-mcp-Muster.
