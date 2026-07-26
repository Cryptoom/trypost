<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Conecte assistentes de IA pra criarem e gerenciarem posts com sua conta TryPost.',
    'step_add' => 'Cole o nome, a URL ou o config abaixo no seu app. O login abre no navegador na primeira conexão.',
    'name_label' => 'Nome',
    'url_label' => 'URL do servidor',
    'config_label' => 'Config',
    'connected_title' => 'Apps conectados',
    'connected_description' => 'Assistentes que já fizeram login. Desconectar corta o acesso na hora.',
    'connected_empty' => 'Nada conectado ainda. Use Claude, ChatGPT ou outro cliente acima.',
    'disconnect' => 'Desconectar',
    'disconnect_title' => 'Desconectar app',
    'disconnect_confirm' => 'Isso desconecta o app do TryPost. Ele precisa reconectar pra usar o MCP de novo.',
    'disconnected' => 'App desconectado.',
    'last_used' => 'Último uso',
    'never' => 'Nunca',
    'documentation_title' => 'Documentação',
    'documentation_description' => 'Guias por cliente, tools disponíveis e solução de problemas.',
    'view_docs' => 'Ver documentação',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Outros apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code e qualquer app que fale MCP.',

    'clients' => [
        'cursor' => 'Adicione o TryPost como servidor MCP remoto no Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Cole o config abaixo nas configurações MCP do VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Cole o config abaixo nas configurações MCP do Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Funciona com qualquer cliente que leia um config mcpServers.',
        'other_name' => 'Outros',
    ],
];
