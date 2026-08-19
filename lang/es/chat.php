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
    'errors' => [
        'turn_in_progress' => 'Esta conversación todavía está respondiendo. Espera a que termine antes de enviar otro mensaje.',
        'request_failed' => 'Algo salió mal al enviar tu mensaje. Inténtalo de nuevo.',
    ],
    'tool_names' => [
        'list_posts' => 'Listar posts',
        'get_post' => 'Detalles del post',
        'get_post_metrics' => 'Métricas del post',
        'create_post' => 'Crear post',
        'update_post' => 'Actualizar post',
        'schedule_post' => 'Programar post',
        'publish_post' => 'Publicar post',
        'delete_post' => 'Eliminar post',
    ],
    'tool_card' => [
        'running' => 'Ejecutando…',
        'denied' => 'Rechazaste esta acción.',
        'unknown_tool' => 'El asistente usó una herramienta para la que esta interfaz aún no tiene una tarjeta.',
        'unreadable_result' => 'Este resultado no se pudo leer.',
        'empty_list' => 'No se encontraron posts.',
        'open_in_editor' => 'Abrir en el editor',
        'untitled' => 'Sin contenido todavía.',
        'post_deleted' => 'Este post se eliminó.',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => 'Aún no publicado',
            'platform_not_supported' => 'Las métricas no están disponibles para esta plataforma',
        ],
        'empty' => 'Todavía no hay métricas que mostrar.',
    ],
    'approval' => [
        'approve' => 'Aprobar',
        'reject' => 'Rechazar',
        'generic_reason' => 'Esta acción necesita tu aprobación antes de ejecutarse.',
    ],
];
