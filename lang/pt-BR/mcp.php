<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Conecte clientes de IA ao TryPost pelo servidor MCP hospedado.',
    'connect_title' => 'Conectar um cliente',
    'connect_description' => 'Instale o TryPost no seu cliente MCP abaixo. O OAuth roda automaticamente na primeira requisição.',
    'step_add' => 'Adicione a entrada abaixo no config MCP do seu cliente (ou cole a URL no diálogo \'Adicionar conector personalizado\'). O fluxo OAuth roda automaticamente na primeira requisição.',
    'name_label' => 'Nome',
    'url_label' => 'URL do servidor MCP remoto',
    'config_label' => 'Configuração',
    'connected_title' => 'Clientes conectados',
    'connected_description' => 'Apps que concluíram o handshake OAuth. Desconectar revoga imediatamente todos os tokens e refresh tokens ligados ao grant.',
    'connected_empty' => 'Nenhum cliente conectado ainda. Use uma das opções acima para conectar seu primeiro cliente.',
    'disconnect' => 'Desconectar',
    'disconnect_title' => 'Desconectar cliente',
    'disconnect_confirm' => 'Isso revoga todos os tokens deste cliente. O app vai parar de funcionar até reconectar.',
    'disconnected' => 'Cliente desconectado.',
    'last_used' => 'Último uso',
    'never' => 'Nunca',
    'documentation_title' => 'Documentação',
    'documentation_description' => 'Aprenda como o MCP hospedado funciona, quais tools ele expõe e como o OAuth mapeia para sua conta.',
    'view_docs' => 'Ver Documentação',
    'connector_name' => 'TryPost',

    'clients' => [
        'claude' => 'Adicione o TryPost como conector personalizado no claude.ai.',
        'chatgpt' => 'Conecte via conectores do modo Developer do ChatGPT. Requer Pro/Business.',
        'cursor' => 'Instalação em um clique via deeplink MCP do Cursor.',
        'vscode' => 'Abre o instalador MCP do VS Code com este servidor preenchido.',
        'claude_code' => 'Adicione o servidor com um único comando de CLI.',
        'other' => 'Use em qualquer cliente que leia um mcpServers.json (Continue, Zed, …).',
        'other_name' => 'Outros clientes',
    ],
];
