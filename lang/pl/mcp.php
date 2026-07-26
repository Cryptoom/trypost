<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Połącz asystentów AI, aby tworzyli i zarządzali postami na koncie TryPost.',
    'step_add' => 'Wklej nazwę, URL lub config poniżej do swojej aplikacji. Logowanie otworzy się w przeglądarce przy pierwszym połączeniu.',
    'name_label' => 'Nazwa',
    'url_label' => 'URL serwera',
    'config_label' => 'Config',
    'connected_title' => 'Połączone aplikacje',
    'connected_description' => 'Asystenci, którzy już się zalogowali. Rozłączenie odcina dostęp od razu.',
    'connected_empty' => 'Nic jeszcze nie połączono. Użyj Claude, ChatGPT lub innego klienta powyżej.',
    'disconnect' => 'Rozłącz',
    'disconnect_title' => 'Rozłącz aplikację',
    'disconnect_confirm' => 'To wyloguje aplikację z TryPost. Musi połączyć się ponownie, zanim znów użyje MCP.',
    'disconnected' => 'Aplikacja rozłączona.',
    'last_used' => 'Ostatnie użycie',
    'never' => 'Nigdy',
    'documentation_title' => 'Dokumentacja',
    'documentation_description' => 'Przewodniki per klient, dostępne tools i rozwiązywanie problemów.',
    'view_docs' => 'Zobacz dokumentację',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Inne aplikacje',
    'other_clients_description' => 'Cursor, VS Code, Claude Code i wszystko, co mówi MCP.',

    'clients' => [
        'cursor' => 'Dodaj TryPost jako zdalny serwer MCP w Cursorze.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Wklej poniższą konfigurację w ustawieniach MCP VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Wklej poniższą konfigurację w ustawieniach MCP Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Działa z każdym klientem, który czyta config mcpServers.',
        'other_name' => 'Inne',
    ],
];
