<?php

declare(strict_types=1);

return [
    'title' => '聊天',
    'headline' => '我能怎样帮你处理帖子？',
    'description' => '询问最近的帖子或它们的表现 — 就像直接和 TryPost MCP 对话，不用 Claude 或 ChatGPT。',
    'placeholder' => '询问帖子或数据',
    'send' => '发送',
    'suggestions_label' => '可以问问',
    'suggestions' => [
        'posts' => '最近的帖子',
        'metrics' => '帖子数据',
    ],
    'coming_soon' => '聊天接通后我就能查。现在只是界面骨架。',
    'tools' => [
        'post_not_found' => '未找到该帖子。',
        'error' => '出了点问题，请重试。',
        'publish_no_enabled_platforms' => '这条帖子没有启用任何平台，发布前请至少启用一个。',
        'delete_blocked' => '这条帖子已经发布，无法从 TryPost 中删除。如果它发布在不支持自动删除的平台上（比如 TikTok），你需要去那边手动删除。',
    ],
    'approvals' => [
        'publish' => '现在发布这条帖子吗？它会立即在每个已启用的平台上线，其中有些平台 — 比如 TikTok — 之后没有办法取消发布。',
        'delete_scheduled' => '删除这条帖子后无法恢复。如果它已安排发布，这也会取消该安排。仍要删除吗？',
    ],
    'errors' => [
        'turn_in_progress' => '该对话仍在回复中。请等待回复完成后再发送新消息。',
    ],
];
