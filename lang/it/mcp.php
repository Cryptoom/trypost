<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Collega assistenti IA così possono creare e gestire post in questo workspace.',
    'step_add' => 'Incolla nome, URL o config qui sotto nella tua app. Il login si apre nel browser al primo collegamento.',
    'name_label' => 'Nome',
    'url_label' => 'URL del server',
    'config_label' => 'Config',
    'connected_title' => 'App collegate',
    'connected_description' => 'Assistenti già autenticati. Scollegare revoca l’accesso subito.',
    'connected_empty' => 'Nessuna connessione ancora. Usa Claude, ChatGPT o un altro client sopra.',
    'disconnect' => 'Scollega',
    'disconnect_title' => 'Scollega app',
    'disconnect_confirm' => 'Questo scollega l’app da TryPost. Dovrà riconnettersi prima di usare di nuovo MCP.',
    'disconnected' => 'App scollegata.',
    'last_used' => 'Ultimo uso',
    'never' => 'Mai',
    'documentation_title' => 'Documentazione',
    'documentation_description' => 'Guide per client, tools disponibili e risoluzione problemi.',
    'view_docs' => 'Vedi documentazione',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Altre app',
    'other_clients_description' => 'Cursor, VS Code, Claude Code e qualsiasi app che parla MCP.',

    'clients' => [
        'cursor' => 'Aggiungi TryPost come server MCP remoto in Cursor.',
        'vscode' => 'Aggiungi TryPost con l’installer MCP di VS Code.',
        'claude_code' => 'Aggiungi il server con un comando CLI.',
        'other' => 'Funziona con qualsiasi client che legge una config mcpServers.',
        'other_name' => 'Altri',
    ],
];
