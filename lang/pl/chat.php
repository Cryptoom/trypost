<?php

declare(strict_types=1);

return [
    'title' => 'Czat',
    'headline' => 'W czym mogę pomóc przy Twoich postach?',
    'description' => 'Zapytaj o ostatnie posty lub ich wyniki — jak rozmowa z MCP TryPost, bez Claude’a i ChatGPT.',
    'placeholder' => 'Zapytaj o posty lub statystyki',
    'send' => 'Wyślij',
    'suggestions_label' => 'Spróbuj zapytać o',
    'suggestions' => [
        'posts' => 'Ostatnie posty',
        'metrics' => 'Statystyki postów',
    ],
    'coming_soon' => 'Sprawdzę to, gdy czat będzie podłączony. Na razie to tylko układ.',
    'tools' => [
        'post_not_found' => 'Nie znaleziono posta.',
        'error' => 'Coś poszło nie tak. Spróbuj ponownie.',
        'publish_no_enabled_platforms' => 'Ten post nie ma żadnej włączonej platformy. Włącz co najmniej jedną przed publikacją.',
        'delete_blocked' => 'Ten post został już opublikowany i nie można go usunąć z TryPost. Jeśli jest widoczny na platformie, która nie obsługuje automatycznego usuwania, np. TikTok, trzeba go usunąć tam ręcznie.',
    ],
    'approvals' => [
        'publish' => 'Opublikować ten post teraz? Pojawi się natychmiast na każdej włączonej platformie, a niektóre platformy — jak TikTok — nie dają możliwości cofnięcia publikacji.',
        'delete_scheduled' => 'Usunięcia tego posta nie da się cofnąć. Jeśli jest zaplanowany do publikacji, to również anuluje ten plan. Usunąć mimo to?',
    ],
    'errors' => [
        'turn_in_progress' => 'Ta rozmowa wciąż generuje odpowiedź. Poczekaj, aż się zakończy, zanim wyślesz kolejną wiadomość.',
    ],
];
