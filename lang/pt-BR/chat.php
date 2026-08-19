<?php

declare(strict_types=1);

return [
    'title' => 'Chat',
    'headline' => 'Como posso ajudar com os seus posts?',
    'description' => 'Pergunte sobre os posts recentes ou o desempenho deles — como falar com o MCP do TryPost, sem Claude ou ChatGPT.',
    'placeholder' => 'Pergunte sobre posts ou métricas',
    'send' => 'Enviar',
    'suggestions_label' => 'Experimente perguntar sobre',
    'suggestions' => [
        'posts' => 'Posts recentes',
        'metrics' => 'Métricas dos posts',
    ],
    'coming_soon' => 'Consigo buscar isso quando o chat estiver conectado. Por enquanto é só o esqueleto.',
    'tools' => [
        'post_not_found' => 'Post não encontrado.',
        'error' => 'Algo deu errado. Tente novamente.',
        'publish_no_enabled_platforms' => 'Este post não tem nenhuma plataforma habilitada. Habilite pelo menos uma antes de publicar.',
        'delete_blocked' => 'Este post já foi publicado e não pode ser excluído do TryPost. Se ele estiver no ar em uma plataforma que não oferece remoção automática, como o TikTok, você precisará excluí-lo por lá manualmente.',
    ],
    'approvals' => [
        'publish' => 'Publicar este post agora? Ele ficará no ar imediatamente em todas as plataformas habilitadas, e algumas — como o TikTok — não oferecem forma de despublicar depois.',
        'delete_scheduled' => 'Excluir este post não pode ser desfeito. Se ele estiver agendado para publicar, isso também cancela o agendamento. Excluir mesmo assim?',
    ],
    'errors' => [
        'turn_in_progress' => 'Esta conversa ainda está respondendo. Aguarde ela terminar antes de enviar outra mensagem.',
        'request_failed' => 'Algo deu errado ao enviar sua mensagem. Tente novamente.',
    ],
];
