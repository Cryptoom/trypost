<?php

declare(strict_types=1);

return [
    'title' => 'Chat',
    'headline' => 'Womit kann ich bei deinen Posts helfen?',
    'description' => 'Frag nach aktuellen Posts oder ihrer Performance — wie mit dem TryPost-MCP, ohne Claude oder ChatGPT.',
    'placeholder' => 'Frag nach Posts oder Kennzahlen',
    'send' => 'Senden',
    'suggestions_label' => 'Frag zum Beispiel nach',
    'suggestions' => [
        'posts' => 'Aktuelle Posts',
        'metrics' => 'Post-Kennzahlen',
    ],
    'coming_soon' => 'Das kann ich nachschlagen, sobald der Chat verbunden ist. Das ist vorerst nur das Layout.',
    'tools' => [
        'post_not_found' => 'Beitrag nicht gefunden.',
        'error' => 'Etwas ist schiefgelaufen. Bitte versuche es erneut.',
        'publish_no_enabled_platforms' => 'Dieser Beitrag hat keine aktivierten Plattformen. Aktiviere mindestens eine, bevor du veröffentlichst.',
        'delete_blocked' => 'Dieser Beitrag wurde bereits veröffentlicht und kann nicht aus TryPost gelöscht werden. Ist er auf einer Plattform live, die keine automatische Entfernung unterstützt, etwa TikTok, musst du ihn dort manuell löschen.',
    ],
    'approvals' => [
        'publish' => 'Diesen Beitrag jetzt veröffentlichen? Er geht sofort auf allen aktivierten Plattformen live, und manche — wie TikTok — bieten danach keine Möglichkeit, ihn wieder zu entfernen.',
        'delete_scheduled' => 'Das Löschen dieses Beitrags lässt sich nicht rückgängig machen. Ist er zur Veröffentlichung geplant, wird dadurch auch das abgebrochen. Trotzdem löschen?',
    ],
    'errors' => [
        'turn_in_progress' => 'Diese Unterhaltung antwortet noch. Warte, bis sie fertig ist, bevor du eine weitere Nachricht sendest.',
        'request_failed' => 'Beim Senden deiner Nachricht ist etwas schiefgelaufen. Bitte versuche es erneut.',
    ],
    'tool_names' => [
        'list_posts' => 'Posts auflisten',
        'get_post' => 'Post-Details',
        'get_post_metrics' => 'Post-Kennzahlen',
        'create_post' => 'Post erstellen',
        'update_post' => 'Post aktualisieren',
        'schedule_post' => 'Post planen',
        'publish_post' => 'Post veröffentlichen',
        'delete_post' => 'Post löschen',
    ],
    'tool_card' => [
        'running' => 'Wird ausgeführt …',
        'denied' => 'Du hast diese Aktion abgelehnt.',
        'unknown_tool' => 'Der Assistent hat ein Tool verwendet, für das diese Oberfläche noch keine Karte hat.',
        'unreadable_result' => 'Dieses Ergebnis konnte nicht gelesen werden.',
        'empty_list' => 'Keine Posts gefunden.',
        'open_in_editor' => 'Im Editor öffnen',
        'untitled' => 'Noch kein Inhalt.',
        'post_deleted' => 'Dieser Beitrag wurde gelöscht.',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => 'Noch nicht veröffentlicht',
            'platform_not_supported' => 'Für diese Plattform sind keine Kennzahlen verfügbar',
        ],
        'empty' => 'Noch keine Kennzahlen vorhanden.',
    ],
    'approval' => [
        'approve' => 'Genehmigen',
        'reject' => 'Ablehnen',
        'generic_reason' => 'Diese Aktion braucht deine Zustimmung, bevor sie ausgeführt wird.',
    ],
];
