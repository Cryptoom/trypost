<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Koppel AI-assistenten zodat ze posts in deze workspace kunnen maken en beheren.',
    'step_add' => 'Plak de naam, URL of config hieronder in je app. Inloggen opent in de browser bij de eerste verbinding.',
    'name_label' => 'Naam',
    'url_label' => 'Server-URL',
    'config_label' => 'Config',
    'connected_title' => 'Gekoppelde apps',
    'connected_description' => 'Assistenten die al zijn ingelogd. Ontkoppelen stopt de toegang meteen.',
    'connected_empty' => 'Nog niets gekoppeld. Gebruik Claude, ChatGPT of een andere client hierboven.',
    'disconnect' => 'Ontkoppelen',
    'disconnect_title' => 'App ontkoppelen',
    'disconnect_confirm' => 'Dit logt de app uit bij TryPost. Hij moet opnieuw verbinden om MCP weer te gebruiken.',
    'disconnected' => 'App ontkoppeld.',
    'last_used' => 'Laatst gebruikt',
    'never' => 'Nooit',
    'documentation_title' => 'Documentatie',
    'documentation_description' => 'Handleidingen per client, beschikbare tools en probleemoplossing.',
    'view_docs' => 'Documentatie bekijken',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Andere apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code en alles wat MCP spreekt.',

    'clients' => [
        'cursor' => 'Voeg TryPost toe als remote MCP-server in Cursor.',
        'vscode' => 'Voeg TryPost toe via de MCP-installer van VS Code.',
        'claude_code' => 'Voeg de server toe met één CLI-commando.',
        'other' => 'Werkt met elke client die een mcpServers-config leest.',
        'other_name' => 'Overig',
    ],
];
