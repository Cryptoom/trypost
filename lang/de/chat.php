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
        'delete_published' => 'Dieser Beitrag ist kein Entwurf, das Löschen lässt sich in TryPost also nicht rückgängig machen. Ist er bereits auf einer Plattform live, die keine automatische Entfernung unterstützt, etwa TikTok, bleibt er dort sichtbar, bis du ihn manuell löschst. Trotzdem löschen?',
    ],
];
