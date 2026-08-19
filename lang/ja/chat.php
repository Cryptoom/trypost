<?php

declare(strict_types=1);

return [
    'title' => 'チャット',
    'headline' => '投稿について、何をお手伝いしましょうか？',
    'description' => '最近の投稿や成果を聞いてください。Claude や ChatGPT なしで TryPost MCP に話しかけるイメージです。',
    'placeholder' => '投稿や指標について聞く',
    'send' => '送信',
    'suggestions_label' => '例えば',
    'suggestions' => [
        'posts' => '最近の投稿',
        'metrics' => '投稿の指標',
    ],
    'coming_soon' => 'チャット接続後に調べられます。今はレイアウトだけです。',
    'tools' => [
        'post_not_found' => '投稿が見つかりません。',
        'error' => '問題が発生しました。もう一度お試しください。',
        'publish_no_enabled_platforms' => 'この投稿には有効なプラットフォームがありません。公開する前に少なくとも1つ有効にしてください。',
        'delete_blocked' => 'この投稿はすでに公開されているため、TryPostから削除できません。TikTokのように自動削除に対応していないプラットフォームで公開中の場合は、そちらで手動で削除してください。',
    ],
    'approvals' => [
        'publish' => 'この投稿を今すぐ公開しますか？有効なすべてのプラットフォームで即座に公開されます。TikTokなど一部のプラットフォームは、公開後に取り消す方法がありません。',
        'delete_scheduled' => 'この投稿の削除は取り消せません。公開予定がある場合、その予定もキャンセルされます。それでも削除しますか？',
    ],
    'errors' => [
        'turn_in_progress' => 'この会話はまだ応答中です。完了してから次のメッセージを送信してください。',
        'request_failed' => 'メッセージの送信中に問題が発生しました。もう一度お試しください。',
    ],
    'tool_names' => [
        'list_posts' => '投稿一覧を表示',
        'get_post' => '投稿の詳細',
        'get_post_metrics' => '投稿の指標',
        'create_post' => '投稿を作成',
        'update_post' => '投稿を更新',
        'schedule_post' => '投稿を予約',
        'publish_post' => '投稿を公開',
        'delete_post' => '投稿を削除',
    ],
    'tool_card' => [
        'running' => '実行中…',
        'denied' => 'この操作を拒否しました。',
        'unknown_tool' => 'アシスタントはこの画面にまだカードが用意されていないツールを使用しました。',
        'unreadable_result' => 'この結果は読み取れませんでした。',
        'empty_list' => '投稿が見つかりません。',
        'open_in_editor' => 'エディタで開く',
        'untitled' => 'まだ内容がありません。',
        'post_deleted' => 'この投稿は削除されました。',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => 'まだ公開されていません',
            'platform_not_supported' => 'このプラットフォームでは指標を利用できません',
        ],
        'empty' => 'まだ表示できる指標がありません。',
    ],
    'approval' => [
        'approve' => '承認',
        'reject' => '拒否',
        'generic_reason' => 'この操作の実行には承認が必要です。',
    ],
];
