<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Подключите ИИ-ассистентов, чтобы они создавали и управляли постами в этом workspace.',
    'step_add' => 'Вставьте имя, URL или config ниже в своё приложение. Вход откроется в браузере при первом подключении.',
    'name_label' => 'Имя',
    'url_label' => 'URL сервера',
    'config_label' => 'Config',
    'connected_title' => 'Подключённые приложения',
    'connected_description' => 'Ассистенты, которые уже вошли. Отключение сразу закрывает доступ.',
    'connected_empty' => 'Пока ничего не подключено. Используйте Claude, ChatGPT или другого клиента выше.',
    'disconnect' => 'Отключить',
    'disconnect_title' => 'Отключить приложение',
    'disconnect_confirm' => 'Это выйдет из аккаунта TryPost в приложении. Нужно будет подключиться снова, чтобы снова использовать MCP.',
    'disconnected' => 'Приложение отключено.',
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
        'vscode' => 'Добавьте TryPost через установщик MCP в VS Code.',
        'claude_code' => 'Добавьте сервер одной CLI-командой.',
        'other' => 'Работает с любым клиентом, который читает config mcpServers.',
        'other_name' => 'Другие',
    ],
];
