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
    'thinking' => 'Sto pensando…',
    'groups' => [
        'today' => 'Oggi',
        'yesterday' => 'Ieri',
        'last_7_days' => 'Ultimi 7 giorni',
        'last_30_days' => 'Ultimi 30 giorni',
        'older' => 'Meno recenti',
    ],
    'tools' => [
        'post_not_found' => 'Post non trovato.',
        'error' => 'Qualcosa è andato storto. Riprova.',
        'publish_no_enabled_platforms' => 'Questo post non ha piattaforme abilitate. Abilitane almeno una prima di pubblicare.',
        'delete_blocked' => 'Questo post è già stato pubblicato e non può essere eliminato da TryPost. Se è attivo su una piattaforma che non supporta la rimozione automatica, come TikTok, dovrai eliminarlo lì manualmente.',
        'forbidden' => 'Non hai il permesso di creare o modificare post in questo spazio di lavoro. Chiedilo a un amministratore dello spazio di lavoro se ti serve.',
    ],
    'approvals' => [
        'publish' => 'Pubblicare subito questo post? Sarà immediatamente attivo su ogni piattaforma abilitata, e alcune — come TikTok — non offrono modo di rimuoverlo in seguito.',
        'delete_scheduled' => 'L’eliminazione di questo post non può essere annullata. Se è programmato per la pubblicazione, questo lo annulla anche. Eliminare comunque?',
    ],
    'errors' => [
        'turn_in_progress' => 'Questa conversazione sta ancora rispondendo. Attendi che finisca prima di inviare un altro messaggio.',
        'request_failed' => 'Si è verificato un errore nell’invio del messaggio. Riprova.',
        'stream_failed' => 'Si è verificato un errore durante la risposta. Riprova.',
        'payment_required_cta' => 'Gestisci fatturazione',
        'access_error' => 'Questa conversazione non è disponibile. Potrebbe essere stata eliminata o appartenere a qualcun altro.',
    ],
    'tool_names' => [
        'list_posts' => 'Elenca post',
        'get_post' => 'Dettagli del post',
        'get_post_metrics' => 'Metriche del post',
        'create_post' => 'Crea post',
        'update_post' => 'Aggiorna post',
        'schedule_post' => 'Pianifica post',
        'publish_post' => 'Pubblica post',
        'delete_post' => 'Elimina post',
    ],
    'tool_card' => [
        'running' => 'In corso…',
        'denied' => 'Hai rifiutato questa azione.',
        'unknown_tool' => 'L’assistente ha usato uno strumento per cui questa interfaccia non ha ancora una scheda.',
        'unreadable_result' => 'Impossibile leggere questo risultato.',
        'empty_list' => 'Nessun post trovato.',
        'open_in_editor' => 'Apri nell’editor',
        'untitled' => 'Nessun contenuto ancora.',
        'post_deleted' => 'Questo post è stato eliminato.',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => 'Non ancora pubblicato',
            'platform_not_supported' => 'Le metriche non sono disponibili per questa piattaforma',
        ],
        'empty' => 'Nessuna metrica da mostrare ancora.',
    ],
    'approval' => [
        'approve' => 'Approva',
        'reject' => 'Rifiuta',
        'generic_reason' => 'Questa azione richiede la tua approvazione prima di essere eseguita.',
    ],
];
