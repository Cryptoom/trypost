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
    'thinking' => 'Pensando…',
    'groups' => [
        'today' => 'Hoje',
        'yesterday' => 'Ontem',
        'last_7_days' => 'Últimos 7 dias',
        'last_30_days' => 'Últimos 30 dias',
        'older' => 'Mais antigos',
    ],
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
        'stream_failed' => 'Algo deu errado ao responder. Tente novamente.',
        'payment_required_cta' => 'Gerenciar cobrança',
        'access_error' => 'Esta conversa não está disponível. Ela pode ter sido excluída ou pertence a outra pessoa.',
    ],
    'tool_names' => [
        'list_posts' => 'Listar posts',
        'get_post' => 'Detalhes do post',
        'get_post_metrics' => 'Métricas do post',
        'create_post' => 'Criar post',
        'update_post' => 'Atualizar post',
        'schedule_post' => 'Agendar post',
        'publish_post' => 'Publicar post',
        'delete_post' => 'Excluir post',
    ],
    'tool_card' => [
        'running' => 'Executando…',
        'denied' => 'Você recusou esta ação.',
        'unknown_tool' => 'O assistente usou uma ferramenta para a qual esta interface ainda não tem um cartão.',
        'unreadable_result' => 'Não foi possível ler este resultado.',
        'empty_list' => 'Nenhum post encontrado.',
        'open_in_editor' => 'Abrir no editor',
        'untitled' => 'Ainda sem conteúdo.',
        'post_deleted' => 'Este post foi excluído.',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => 'Ainda não publicado',
            'platform_not_supported' => 'As métricas não estão disponíveis para esta plataforma',
        ],
        'empty' => 'Ainda não há métricas para mostrar.',
    ],
    'approval' => [
        'approve' => 'Aprovar',
        'reject' => 'Recusar',
        'generic_reason' => 'Esta ação precisa da sua aprovação antes de ser executada.',
    ],
];
