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
        'delete_published' => 'Deze post is geen concept, dus verwijderen kan niet ongedaan worden gemaakt in TryPost. Staat hij al live op een platform dat geen automatische verwijdering ondersteunt, zoals TikTok, dan blijft hij daar zichtbaar tot je hem handmatig verwijdert. Toch verwijderen?',
    ],
];
