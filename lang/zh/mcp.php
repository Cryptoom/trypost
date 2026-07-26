<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => '连接 AI 助手，让它们在此工作区创建和管理帖子。',
    'step_add' => '将下方的名称、URL 或配置粘贴到你的应用中。首次连接时会在浏览器中打开登录。',
    'name_label' => '名称',
    'url_label' => '服务器 URL',
    'config_label' => '配置',
    'connected_title' => '已连接的应用',
    'connected_description' => '已完成登录的助手。断开连接会立即撤销访问权限。',
    'connected_empty' => '还没有连接。请使用上方的 Claude、ChatGPT 或其他客户端。',
    'disconnect' => '断开连接',
    'disconnect_title' => '断开应用',
    'disconnect_confirm' => '这将使应用退出 TryPost。再次使用 MCP 前需要重新连接。',
    'disconnected' => '应用已断开连接。',
    'last_used' => '最近使用',
    'never' => '从未',
    'documentation_title' => '文档',
    'documentation_description' => '各客户端设置指南、可用工具和问题排查。',
    'view_docs' => '查看文档',
    'connector_name' => 'TryPost',

    'other_clients_title' => '其他应用',
    'other_clients_description' => 'Cursor、VS Code、Claude Code 以及任何支持 MCP 的应用。',

    'clients' => [
        'cursor' => '在 Cursor 中将 TryPost 添加为远程 MCP 服务器。',
        'vscode' => '通过 VS Code 的 MCP 安装程序添加 TryPost。',
        'claude_code' => '用一条 CLI 命令添加服务器。',
        'other' => '适用于任何读取 mcpServers 配置的客户端。',
        'other_name' => '其他',
    ],
];
