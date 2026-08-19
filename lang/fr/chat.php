<?php

declare(strict_types=1);

return [
    'title' => 'Chat',
    'headline' => 'Comment puis-je aider avec vos posts ?',
    'description' => 'Demandez vos posts récents ou leurs performances — comme parler au MCP TryPost, sans Claude ni ChatGPT.',
    'placeholder' => 'Posez une question sur vos posts ou métriques',
    'send' => 'Envoyer',
    'suggestions_label' => 'Essayez de demander',
    'suggestions' => [
        'posts' => 'Posts récents',
        'metrics' => 'Métriques des posts',
    ],
    'coming_soon' => 'Je pourrai chercher ça une fois le chat connecté. Pour l’instant, c’est juste la mise en page.',
    'tools' => [
        'post_not_found' => 'Post introuvable.',
        'error' => 'Une erreur s’est produite. Réessayez.',
        'publish_no_enabled_platforms' => 'Ce post n’a aucune plateforme activée. Activez-en au moins une avant de publier.',
        'delete_blocked' => 'Ce post a déjà été publié et ne peut pas être supprimé de TryPost. S’il est en ligne sur une plateforme qui ne prend pas en charge la suppression automatique, comme TikTok, vous devrez le supprimer vous-même là-bas.',
    ],
    'approvals' => [
        'publish' => 'Publier ce post maintenant ? Il sera immédiatement en ligne sur chaque plateforme activée, et certaines — comme TikTok — n’offrent aucun moyen de le dépublier ensuite.',
        'delete_scheduled' => 'La suppression de ce post est irréversible. S’il est programmé pour être publié, cela l’annule aussi. Le supprimer quand même ?',
    ],
    'errors' => [
        'turn_in_progress' => 'Cette conversation est encore en train de répondre. Attendez la fin avant d\'envoyer un autre message.',
        'request_failed' => 'Une erreur s’est produite lors de l’envoi de votre message. Réessayez.',
    ],
    'tool_names' => [
        'list_posts' => 'Lister les posts',
        'get_post' => 'Détails du post',
        'get_post_metrics' => 'Métriques du post',
        'create_post' => 'Créer un post',
        'update_post' => 'Mettre à jour le post',
        'schedule_post' => 'Planifier le post',
        'publish_post' => 'Publier le post',
        'delete_post' => 'Supprimer le post',
    ],
    'tool_card' => [
        'running' => 'En cours…',
        'denied' => 'Vous avez refusé cette action.',
        'unknown_tool' => 'L’assistant a utilisé un outil pour lequel cette interface n’a pas encore de carte.',
        'unreadable_result' => 'Ce résultat n’a pas pu être lu.',
        'empty_list' => 'Aucun post trouvé.',
        'open_in_editor' => 'Ouvrir dans l’éditeur',
        'untitled' => 'Pas encore de contenu.',
        'post_deleted' => 'Ce post a été supprimé.',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => 'Pas encore publié',
            'platform_not_supported' => 'Les métriques ne sont pas disponibles pour cette plateforme',
        ],
        'empty' => 'Aucune métrique à afficher pour l’instant.',
    ],
    'approval' => [
        'approve' => 'Approuver',
        'reject' => 'Refuser',
        'generic_reason' => 'Cette action nécessite votre approbation avant de s’exécuter.',
    ],
];
