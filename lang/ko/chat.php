<?php

declare(strict_types=1);

return [
    'title' => '채팅',
    'headline' => '게시물에 대해 무엇을 도와드릴까요?',
    'description' => '최근 게시물이나 성과를 물어보세요. Claude나 ChatGPT 없이 TryPost MCP와 대화하는 것과 같습니다.',
    'placeholder' => '게시물이나 지표에 대해 물어보세요',
    'send' => '보내기',
    'suggestions_label' => '이런 걸 물어보세요',
    'suggestions' => [
        'posts' => '최근 게시물',
        'metrics' => '게시물 지표',
    ],
    'thinking' => '생각 중…',
    'groups' => [
        'today' => '오늘',
        'yesterday' => '어제',
        'last_7_days' => '지난 7일',
        'last_30_days' => '지난 30일',
        'older' => '이전',
    ],
    'tools' => [
        'post_not_found' => '게시물을 찾을 수 없습니다.',
        'error' => '문제가 발생했습니다. 다시 시도해 주세요.',
        'publish_no_enabled_platforms' => '이 게시물에는 활성화된 플랫폼이 없습니다. 게시하기 전에 최소 하나를 활성화하세요.',
        'delete_blocked' => '이 게시물은 이미 게시되어 TryPost에서 삭제할 수 없습니다. TikTok처럼 자동 삭제를 지원하지 않는 플랫폼에 게시되어 있다면 해당 플랫폼에서 직접 삭제해야 합니다.',
    ],
    'approvals' => [
        'publish' => '지금 이 게시물을 게시할까요? 활성화된 모든 플랫폼에 즉시 게시되며, TikTok과 같은 일부 플랫폼은 이후 게시를 취소할 방법이 없습니다.',
        'delete_scheduled' => '이 게시물을 삭제하면 되돌릴 수 없습니다. 게시 예약이 되어 있다면 그 예약도 함께 취소됩니다. 그래도 삭제할까요?',
    ],
    'errors' => [
        'turn_in_progress' => '이 대화는 아직 응답 중입니다. 완료된 뒤에 다음 메시지를 보내세요.',
        'request_failed' => '메시지를 보내는 중 문제가 발생했습니다. 다시 시도해 주세요.',
        'stream_failed' => '응답하는 중 문제가 발생했습니다. 다시 시도해 주세요.',
        'payment_required_cta' => '결제 관리',
        'access_error' => '이 대화를 사용할 수 없습니다. 삭제되었거나 다른 사용자의 대화일 수 있습니다.',
    ],
    'tool_names' => [
        'list_posts' => '게시물 목록',
        'get_post' => '게시물 상세정보',
        'get_post_metrics' => '게시물 지표',
        'create_post' => '게시물 만들기',
        'update_post' => '게시물 업데이트',
        'schedule_post' => '게시물 예약',
        'publish_post' => '게시물 게시',
        'delete_post' => '게시물 삭제',
    ],
    'tool_card' => [
        'running' => '실행 중…',
        'denied' => '이 작업을 거부했습니다.',
        'unknown_tool' => '어시스턴트가 이 화면에 아직 카드가 없는 도구를 사용했습니다.',
        'unreadable_result' => '이 결과를 읽을 수 없습니다.',
        'empty_list' => '게시물을 찾을 수 없습니다.',
        'open_in_editor' => '편집기에서 열기',
        'untitled' => '아직 내용이 없습니다.',
        'post_deleted' => '이 게시물은 삭제되었습니다.',
    ],
    'metrics' => [
        'unsupported' => [
            'not_published' => '아직 게시되지 않음',
            'platform_not_supported' => '이 플랫폼에서는 지표를 사용할 수 없습니다',
        ],
        'empty' => '아직 표시할 지표가 없습니다.',
    ],
    'approval' => [
        'approve' => '승인',
        'reject' => '거부',
        'generic_reason' => '이 작업을 실행하려면 승인이 필요합니다.',
    ],
];
