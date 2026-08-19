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
    'errors' => [
        'turn_in_progress' => 'This conversation is still replying. Wait for it to finish before sending another message.',
        'request_failed' => 'Something went wrong sending your message. Please try again.',
    ],
    'tool_names' => [
        'list_posts' => 'List posts',
        'get_post' => 'Post details',
        'get_post_metrics' => 'Post metrics',
        'create_post' => 'Create post',
        'update_post' => 'Update post',
        'schedule_post' => 'Schedule post',
        'publish_post' => 'Publish post',
        'delete_post' => 'Delete post',
    ],
    'tool_card' => [
        'running' => 'Running…',
        'denied' => 'You declined this action.',
        'unknown_tool' => "This assistant used a tool this interface doesn't have a card for yet.",
        'unreadable_result' => "This result couldn't be read.",
        'empty_list' => 'No posts found.',
        'open_in_editor' => 'Open in editor',
        'untitled' => 'No content yet.',
        'post_deleted' => 'This post was deleted.',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => 'Not published yet',
            'platform_not_supported' => "Metrics aren't available for this platform",
        ],
        'empty' => 'No metrics to show yet.',
    ],
    'approval' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'generic_reason' => 'This action needs your approval before it happens.',
    ],
];
