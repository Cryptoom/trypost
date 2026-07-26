<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'TryPostアカウントで投稿の作成・管理ができるよう、AIアシスタントを接続します。',
    'step_add' => '下の名前・URL・設定をアプリに貼り付けてください。初回接続時はブラウザでログインが開きます。',
    'name_label' => '名前',
    'url_label' => 'サーバーURL',
    'config_label' => '設定',
    'connected_title' => '接続済みアプリ',
    'connected_description' => 'すでにログイン済みのアシスタントです。切断するとすぐにアクセスが失効します。',
    'connected_empty' => 'まだ接続がありません。上の Claude、ChatGPT、または他のクライアントを使ってください。',
    'disconnect' => '切断',
    'disconnect_title' => 'アプリを切断',
    'disconnect_confirm' => 'TryPostからアプリを切断します。再度MCPを使うには再接続が必要です。',
    'disconnected' => 'アプリを切断しました。',
    'last_used' => '最終使用',
    'never' => 'なし',
    'documentation_title' => 'ドキュメント',
    'documentation_description' => 'クライアント別のセットアップ、利用可能なツール、トラブルシューティング。',
    'view_docs' => 'ドキュメントを見る',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'その他のアプリ',
    'other_clients_description' => 'Cursor、VS Code、Claude Code、その他MCP対応アプリ。',

    'clients' => [
        'cursor' => 'CursorでTryPostをリモートMCPサーバーとして追加します。',
        'vscode' => '下の設定をVS CodeのMCP設定に貼り付けます。',
        'claude_code' => '下の設定をClaude CodeのMCP設定に貼り付けます。',
        'other' => 'mcpServers設定を読むクライアントならどれでも使えます。',
        'other_name' => 'その他',
    ],
];
