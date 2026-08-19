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
];
