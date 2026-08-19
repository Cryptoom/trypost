<?php

declare(strict_types=1);

return [
    'title' => 'Chat',
    'headline' => 'Hoe kan ik helpen met je posts?',
    'description' => 'Vraag naar recente posts of hun prestaties — alsof je met de TryPost-MCP praat, zonder Claude of ChatGPT.',
    'placeholder' => 'Vraag naar posts of statistieken',
    'send' => 'Versturen',
    'suggestions_label' => 'Probeer te vragen naar',
    'suggestions' => [
        'posts' => 'Recente posts',
        'metrics' => 'Poststatistieken',
    ],
    'coming_soon' => 'Dat kan ik opzoeken zodra chat verbonden is. Dit is voorlopig alleen de layout.',
    'tools' => [
        'post_not_found' => 'Post niet gevonden.',
        'error' => 'Er is iets misgegaan. Probeer het opnieuw.',
        'publish_no_enabled_platforms' => 'Deze post heeft geen geactiveerde platforms. Activeer er minstens één voordat je publiceert.',
        'delete_blocked' => 'Deze post is al gepubliceerd en kan niet uit TryPost worden verwijderd. Staat hij live op een platform dat geen automatische verwijdering ondersteunt, zoals TikTok, dan moet je hem daar handmatig verwijderen.',
    ],
    'approvals' => [
        'publish' => 'Deze post nu publiceren? Hij komt meteen live op elk geactiveerd platform, en sommige platforms — zoals TikTok — bieden achteraf geen manier om hem weer offline te halen.',
        'delete_scheduled' => 'Het verwijderen van deze post kan niet ongedaan worden gemaakt. Staat hij gepland om te publiceren, dan wordt dat hiermee ook geannuleerd. Toch verwijderen?',
    ],
    'errors' => [
        'turn_in_progress' => 'Dit gesprek is nog aan het antwoorden. Wacht tot het klaar is voordat je een nieuw bericht stuurt.',
        'request_failed' => 'Er is iets misgegaan bij het versturen van je bericht. Probeer het opnieuw.',
    ],
    'tool_names' => [
        'list_posts' => 'Posts weergeven',
        'get_post' => 'Postdetails',
        'get_post_metrics' => 'Poststatistieken',
        'create_post' => 'Post aanmaken',
        'update_post' => 'Post bijwerken',
        'schedule_post' => 'Post plannen',
        'publish_post' => 'Post publiceren',
        'delete_post' => 'Post verwijderen',
    ],
    'tool_card' => [
        'running' => 'Bezig…',
        'denied' => 'Je hebt deze actie geweigerd.',
        'unknown_tool' => 'De assistent gebruikte een tool waar deze interface nog geen kaart voor heeft.',
        'unreadable_result' => 'Dit resultaat kon niet worden gelezen.',
        'empty_list' => 'Geen posts gevonden.',
        'open_in_editor' => 'Openen in editor',
        'untitled' => 'Nog geen inhoud.',
        'post_deleted' => 'Deze post is verwijderd.',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => 'Nog niet gepubliceerd',
            'platform_not_supported' => 'Statistieken zijn niet beschikbaar voor dit platform',
        ],
        'empty' => 'Nog geen statistieken om te tonen.',
    ],
    'approval' => [
        'approve' => 'Goedkeuren',
        'reject' => 'Weigeren',
        'generic_reason' => 'Deze actie heeft jouw goedkeuring nodig voordat hij wordt uitgevoerd.',
    ],
];
