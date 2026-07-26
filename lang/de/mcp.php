<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Verbinde KI-Assistenten, damit sie Beiträge in diesem Workspace erstellen und verwalten können.',
    'step_add' => 'Füge Name, URL oder Config unten in deine App ein. Die Anmeldung öffnet sich beim ersten Verbinden im Browser.',
    'name_label' => 'Name',
    'url_label' => 'Server-URL',
    'config_label' => 'Config',
    'connected_title' => 'Verbundene Apps',
    'connected_description' => 'Assistenten, die sich bereits angemeldet haben. Trennen entzieht den Zugriff sofort.',
    'connected_empty' => 'Noch nichts verbunden. Nutze Claude, ChatGPT oder einen anderen Client oben.',
    'disconnect' => 'Trennen',
    'disconnect_title' => 'App trennen',
    'disconnect_confirm' => 'Dadurch wird die App von TryPost abgemeldet. Sie muss sich neu verbinden, bevor sie MCP wieder nutzen kann.',
    'disconnected' => 'App getrennt.',
    'last_used' => 'Zuletzt verwendet',
    'never' => 'Nie',
    'documentation_title' => 'Dokumentation',
    'documentation_description' => 'Einrichtungsguides pro Client, verfügbare Tools und Fehlerhilfe.',
    'view_docs' => 'Dokumentation ansehen',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Andere Apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code und alles andere, das MCP spricht.',

    'clients' => [
        'cursor' => 'Füge TryPost in Cursor als Remote-MCP-Server hinzu.',
        'vscode' => 'Füge TryPost über den MCP-Installer von VS Code hinzu.',
        'claude_code' => 'Füge den Server mit einem CLI-Befehl hinzu.',
        'other' => 'Funktioniert mit jedem Client, der eine mcpServers-Config liest.',
        'other_name' => 'Andere',
    ],
];
