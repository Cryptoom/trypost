<?php

declare(strict_types=1);

return [
    'title' => 'Chat',
    'headline' => '¿Cómo puedo ayudarte con tus posts?',
    'description' => 'Pregunta por los posts recientes o su rendimiento — como hablar con el MCP de TryPost, sin Claude ni ChatGPT.',
    'placeholder' => 'Pregunta por posts o métricas',
    'send' => 'Enviar',
    'suggestions_label' => 'Prueba a preguntar por',
    'suggestions' => [
        'posts' => 'Posts recientes',
        'metrics' => 'Métricas de posts',
    ],
    'coming_soon' => 'Podré buscarlo cuando el chat esté conectado. Por ahora solo es el diseño.',
    'tools' => [
        'post_not_found' => 'Post no encontrado.',
        'error' => 'Algo salió mal. Inténtalo de nuevo.',
        'publish_no_enabled_platforms' => 'Este post no tiene plataformas activadas. Activa al menos una antes de publicar.',
        'delete_blocked' => 'Este post ya se publicó y no se puede eliminar de TryPost. Si está activo en una plataforma que no admite la eliminación automática, como TikTok, tendrás que eliminarlo allí manualmente.',
    ],
    'approvals' => [
        'publish' => '¿Publicar este post ahora? Se publicará de inmediato en todas las plataformas activadas, y algunas — como TikTok — no ofrecen forma de despublicarlo después.',
        'delete_scheduled' => 'Eliminar este post no se puede deshacer. Si está programado para publicarse, esto también lo cancela. ¿Eliminar de todas formas?',
    ],
];
