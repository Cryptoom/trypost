<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Подключите ИИ-ассистентов, чтобы они создавали и управляли постами в вашем аккаунте TryPost.',
    'step_add' => 'Вставьте имя, URL или config ниже в своё приложение. Вход откроется в браузере при первом подключении.',
    'name_label' => 'Имя',
    'url_label' => 'URL сервера',
    'config_label' => 'Config',
    'connected_title' => 'Подключённые приложения',
    'connected_description' => 'Ассистенты, вошедшие от имени кого угодно в этом аккаунте. Отключить можно только свои.',
    'connected_empty' => 'Пока ничего не подключено. Используйте Claude, ChatGPT или другого клиента выше.',
    'connected_by' => 'Подключил :name',
    'disconnect' => 'Отключить',
    'disconnect_title' => 'Отключить приложение',
    'disconnect_confirm' => 'Это выйдет из аккаунта TryPost в приложении. Нужно будет подключиться снова, чтобы снова использовать MCP.',
    'disconnected' => 'Приложение отключено.',
    'copied' => 'Скопировано',
    'last_used' => 'Последнее использование',
    'never' => 'Никогда',
    'documentation_title' => 'Документация',
    'documentation_description' => 'Гайды по клиентам, доступные tools и решение проблем.',
    'view_docs' => 'Открыть документацию',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Другие приложения',
    'other_clients_description' => 'Cursor, VS Code, Claude Code и всё, что говорит на MCP.',

    'clients' => [
        'cursor' => 'Добавьте TryPost как удалённый MCP-сервер в Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Вставьте конфиг ниже в настройки MCP VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Вставьте конфиг ниже в настройки MCP Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Работает с любым клиентом, который читает config mcpServers.',
        'other_name' => 'Другие',
    ],
];
