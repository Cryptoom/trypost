<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'اربط مساعدي الذكاء الاصطناعي لإنشاء المنشورات وإدارتها بحساب TryPost الخاص بك.',
    'step_add' => 'الصق الاسم أو الرابط أو الإعداد أدناه في تطبيقك. يفتح تسجيل الدخول في المتصفح عند أول اتصال.',
    'name_label' => 'الاسم',
    'url_label' => 'رابط الخادم',
    'config_label' => 'الإعداد',
    'connected_title' => 'التطبيقات المتصلة',
    'connected_description' => 'المساعدون الذين سجّلوا الدخول بالفعل. قطع الاتصال يلغي الوصول فورًا.',
    'connected_empty' => 'لا يوجد اتصال بعد. استخدم Claude أو ChatGPT أو عميلًا آخر أعلاه.',
    'disconnect' => 'قطع الاتصال',
    'disconnect_title' => 'قطع اتصال التطبيق',
    'disconnect_confirm' => 'يؤدي هذا إلى تسجيل خروج التطبيق من TryPost. سيحتاج إلى إعادة الاتصال قبل استخدام MCP مجددًا.',
    'disconnected' => 'تم قطع اتصال التطبيق.',
    'last_used' => 'آخر استخدام',
    'never' => 'أبدًا',
    'documentation_title' => 'التوثيق',
    'documentation_description' => 'أدلة الإعداد لكل عميل، والأدوات المتاحة، وحل المشكلات.',
    'view_docs' => 'عرض التوثيق',
    'connector_name' => 'TryPost',

    'other_clients_title' => 'تطبيقات أخرى',
    'other_clients_description' => 'Cursor وVS Code وClaude Code وأي تطبيق يدعم MCP.',

    'clients' => [
        'cursor' => 'أضف TryPost كخادم MCP بعيد في Cursor.',
        'vscode' => 'الصق الإعداد أدناه في إعدادات MCP في VS Code.',
        'claude_code' => 'الصق الإعداد أدناه في إعدادات MCP في Claude Code.',
        'other' => 'يعمل مع أي عميل يقرأ إعداد mcpServers.',
        'other_name' => 'أخرى',
    ],
];
