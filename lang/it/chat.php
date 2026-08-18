<?php

declare(strict_types=1);

return [
    'title' => 'Chat',
    'headline' => 'Come posso aiutarti con i tuoi post?',
    'description' => 'Chiedi dei post recenti o delle loro performance — come parlare con l’MCP di TryPost, senza Claude o ChatGPT.',
    'placeholder' => 'Chiedi dei post o delle metriche',
    'send' => 'Invia',
    'suggestions_label' => 'Prova a chiedere',
    'suggestions' => [
        'posts' => 'Post recenti',
        'metrics' => 'Metriche dei post',
    ],
    'coming_soon' => 'Potrò cercarlo quando la chat sarà collegata. Per ora è solo il layout.',
    'tools' => [
        'post_not_found' => 'Post non trovato.',
        'error' => 'Qualcosa è andato storto. Riprova.',
        'publish_no_enabled_platforms' => 'Questo post non ha piattaforme abilitate. Abilitane almeno una prima di pubblicare.',
        'delete_blocked' => 'Questo post è già stato pubblicato e non può essere eliminato da TryPost. Se è attivo su una piattaforma che non supporta la rimozione automatica, come TikTok, dovrai eliminarlo lì manualmente.',
    ],
    'approvals' => [
        'publish' => 'Pubblicare subito questo post? Sarà immediatamente attivo su ogni piattaforma abilitata, e alcune — come TikTok — non offrono modo di rimuoverlo in seguito.',
        'delete_scheduled' => 'L’eliminazione di questo post non può essere annullata. Se è programmato per la pubblicazione, questo lo annulla anche. Eliminare comunque?',
    ],
    'errors' => [
        'turn_in_progress' => 'Questa conversazione sta ancora rispondendo. Attendi che finisca prima di inviare un altro messaggio.',
    ],
];
