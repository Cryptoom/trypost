<?php

declare(strict_types=1);

return [
    'title' => 'Sohbet',
    'headline' => 'Gönderilerinle nasıl yardımcı olabilirim?',
    'description' => 'Son gönderileri veya performanslarını sorun — Claude veya ChatGPT olmadan TryPost MCP ile konuşur gibi.',
    'placeholder' => 'Gönderiler veya metrikler hakkında sorun',
    'send' => 'Gönder',
    'suggestions_label' => 'Şunları sorabilirsiniz',
    'suggestions' => [
        'posts' => 'Son gönderiler',
        'metrics' => 'Gönderi metrikleri',
    ],
    'coming_soon' => 'Sohbet bağlandığında bakabilirim. Şimdilik yalnızca düzen.',
    'tools' => [
        'post_not_found' => 'Gönderi bulunamadı.',
        'error' => 'Bir şeyler ters gitti. Lütfen tekrar deneyin.',
        'publish_no_enabled_platforms' => 'Bu gönderide etkin platform yok. Yayınlamadan önce en az birini etkinleştirin.',
        'delete_blocked' => 'Bu gönderi zaten yayınlandı ve TryPost’tan silinemez. Otomatik kaldırmayı desteklemeyen bir platformda yayındaysa, örneğin TikTok, orada elle silmeniz gerekir.',
    ],
    'approvals' => [
        'publish' => 'Bu gönderi şimdi yayınlansın mı? Etkin olan her platformda hemen yayına girer ve TikTok gibi bazı platformlarda daha sonra yayından kaldırmanın bir yolu yoktur.',
        'delete_scheduled' => 'Bu gönderiyi silmek geri alınamaz. Yayınlanmak üzere zamanlanmışsa, bu işlem onu da iptal eder. Yine de silinsin mi?',
    ],
    'errors' => [
        'turn_in_progress' => 'Bu sohbet hâlâ yanıt veriyor. Yeni bir mesaj göndermeden önce bitmesini bekleyin.',
    ],
];
