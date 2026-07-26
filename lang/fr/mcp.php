<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Connectez des clients IA à TryPost via le serveur MCP hébergé.',
    'connect_title' => 'Connect a client',
    'connect_description' => 'Installez TryPost dans votre client MCP ci-dessous. OAuth s\'exécute automatiquement à la première requête.',
    'step_add' => 'Add the entry below to your client\'s MCP config (or paste the URL into its \'Add custom connector\' dialog). The OAuth flow runs automatically on first request.',
    'name_label' => 'Name',
    'url_label' => 'Remote MCP server URL',
    'config_label' => 'Configuration',
    'connected_title' => 'Connected clients',
    'connected_description' => 'Apps that have completed the OAuth handshake. Disconnecting revokes every API token and refresh token tied to the grant immediately.',
    'connected_empty' => 'No clients connected yet. Use one of the options above to connect your first client.',
    'disconnect' => 'Disconnect',
    'disconnect_title' => 'Disconnect client',
    'disconnect_confirm' => 'This revokes every token tied to this client. The app will stop working until it reconnects.',
    'disconnected' => 'Client disconnected.',
    'last_used' => 'Last used',
    'never' => 'Never',
    'documentation_title' => 'Documentation',
    'documentation_description' => 'Learn how the hosted MCP works, what tools it exposes, and how OAuth maps to your account.',
    'view_docs' => 'View Documentation',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Other clients',
    'other_clients_description' => 'Paste the TryPost MCP URL or config into Cursor, VS Code, Claude Code, and other MCP-compatible apps.',

    'clients' => [
        'claude' => 'Add TryPost as a custom connector inside claude.ai.',
        'chatgpt' => 'Connect via ChatGPT\'s Developer mode connectors. Requires Pro/Business.',
        'cursor' => 'One-click install via the Cursor MCP deeplink.',
        'vscode' => 'Open VS Code\'s MCP installer with this server pre-filled.',
        'claude_code' => 'Add the server with a single CLI command.',
        'other' => 'Drop this into any client that reads an mcpServers.json (Continue, Zed, …).',
        'other_name' => 'Other clients',
    ],
];
