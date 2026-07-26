<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'TryPost hesabınızla gönderi oluşturup yönetmeleri için yapay zeka asistanlarını bağlayın.',
    'step_add' => 'Adı, URL’yi veya config’i aşağıdaki gibi uygulamanıza yapıştırın. İlk bağlantıda oturum açma tarayıcıda açılır.',
    'name_label' => 'Ad',
    'url_label' => 'Sunucu URL’si',
    'config_label' => 'Config',
    'connected_title' => 'Bağlı uygulamalar',
    'connected_description' => 'Zaten giriş yapmış asistanlar. Bağlantıyı kesmek erişimi hemen keser.',
    'connected_empty' => 'Henüz bağlı bir şey yok. Yukarıdan Claude, ChatGPT veya başka bir istemci kullanın.',
    'disconnect' => 'Bağlantıyı kes',
    'disconnect_title' => 'Uygulama bağlantısını kes',
    'disconnect_confirm' => 'Bu, uygulamayı TryPost’tan çıkarır. MCP’yi yeniden kullanmak için tekrar bağlanması gerekir.',
    'disconnected' => 'Uygulama bağlantısı kesildi.',
    'last_used' => 'Son kullanım',
    'never' => 'Hiç',
    'documentation_title' => 'Dokümantasyon',
    'documentation_description' => 'İstemci kurulum rehberleri, kullanılabilir tools ve sorun giderme.',
    'view_docs' => 'Dokümantasyonu görüntüle',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'Diğer uygulamalar',
    'other_clients_description' => 'Cursor, VS Code, Claude Code ve MCP konuşan diğer her şey.',

    'clients' => [
        'cursor' => 'Cursor’da TryPost’u uzak MCP sunucusu olarak ekleyin.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Aşağıdaki yapılandırmayı VS Code\'un MCP ayarlarına yapıştırın.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Aşağıdaki yapılandırmayı Claude Code\'un MCP ayarlarına yapıştırın.',
        'claude_code_name' => 'Claude Code',
        'other' => 'mcpServers config okuyan her istemciyle çalışır.',
        'other_name' => 'Diğer',
    ],
];
