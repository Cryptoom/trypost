<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Connect AI assistants so they can create and manage posts with your TryPost account.',
    'step_add' => 'Paste the name, URL, or config below into your app. Sign-in opens in the browser the first time it connects.',
    'name_label' => 'Name',
    'url_label' => 'Server URL',
    'config_label' => 'Config',
    'connected_title' => 'Connected apps',
    'connected_description' => 'Assistants that already signed in. Disconnect cuts their access immediately.',
    'connected_empty' => 'Nothing connected yet. Use Claude, ChatGPT, or another client above.',
    'disconnect' => 'Disconnect',
    'disconnect_title' => 'Disconnect app',
    'disconnect_confirm' => 'This signs the app out of TryPost. It will need to reconnect before it can use MCP again.',
    'disconnected' => 'App disconnected.',
    'last_used' => 'Last used',
    'never' => 'Never',
    'documentation_title' => 'Documentation',
    'documentation_description' => 'Client setup guides, available tools, and troubleshooting.',
    'view_docs' => 'View docs',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Other apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code, and anything else that speaks MCP.',

    'clients' => [
        'cursor' => 'Add TryPost as a remote MCP server in Cursor.',
        'vscode' => 'Paste the config below into VS Code\'s MCP settings.',
        'claude_code' => 'Paste the config below into Claude Code\'s MCP settings.',
        'other' => 'Works with any client that reads an mcpServers config.',
        'other_name' => 'Other',
    ],
];
