<?php

declare(strict_types=1);

return [
    'title' => 'Chat',
    'headline' => 'How can I help with your posts?',
    'description' => 'Ask about recent posts or how they performed — like talking to TryPost MCP, without Claude or ChatGPT.',
    'placeholder' => 'Ask about your posts or metrics',
    'send' => 'Send',
    'suggestions_label' => 'Try asking about',
    'suggestions' => [
        'posts' => 'Recent posts',
        'metrics' => 'Post metrics',
    ],
    'coming_soon' => 'I can look that up once chat is connected. This is just the layout for now.',
    'tools' => [
        'post_not_found' => 'Post not found.',
        'error' => 'Something went wrong. Please try again.',
        'publish_no_enabled_platforms' => 'This post has no enabled platforms. Enable at least one before publishing.',
        'delete_blocked' => "This post has already been published and can't be deleted from TryPost. If it's live on a platform that doesn't support automatic removal, like TikTok, remove it there manually.",
    ],
    'approvals' => [
        'publish' => 'Publish this post now? It will go live immediately on every enabled platform, and some platforms — like TikTok — offer no way to unpublish afterward.',
        'delete_scheduled' => 'Deleting this post can\'t be undone. If it\'s scheduled to publish, this also cancels that. Delete anyway?',
    ],
];
