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
    'thinking' => '思考中…',
    'groups' => [
        'today' => '今天',
        'yesterday' => '昨天',
        'last_7_days' => '过去 7 天',
        'last_30_days' => '过去 30 天',
        'older' => '更早',
    ],
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
        'request_failed' => '发送消息时出了点问题，请重试。',
        'stream_failed' => '回复时出了点问题，请重试。',
        'payment_required_cta' => '管理账单',
        'access_error' => '此对话不可用，可能已被删除，或属于其他用户。',
    ],
    'tool_names' => [
        'list_posts' => '列出帖子',
        'get_post' => '帖子详情',
        'get_post_metrics' => '帖子数据',
        'create_post' => '创建帖子',
        'update_post' => '更新帖子',
        'schedule_post' => '安排帖子',
        'publish_post' => '发布帖子',
        'delete_post' => '删除帖子',
    ],
    'tool_card' => [
        'running' => '执行中…',
        'denied' => '你已拒绝此操作。',
        'unknown_tool' => '助手使用了一个此界面尚无对应卡片的工具。',
        'unreadable_result' => '无法读取此结果。',
        'empty_list' => '未找到帖子。',
        'open_in_editor' => '在编辑器中打开',
        'untitled' => '暂无内容。',
        'post_deleted' => '该帖子已被删除。',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => '尚未发布',
            'platform_not_supported' => '该平台暂不支持数据',
        ],
        'empty' => '暂无可显示的数据。',
    ],
    'approval' => [
        'approve' => '批准',
        'reject' => '拒绝',
        'generic_reason' => '此操作需要你批准后才能执行。',
    ],
];
