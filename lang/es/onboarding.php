<?php

declare(strict_types=1);

return [
    'title' => 'Activate your TryPost workspace',
    'description' => 'Complete these three essentials to connect your tools, publish everywhere, and start creating.',
    'skip' => 'Skip for now',
    'continue' => 'Continue to TryPost',
    'mcp' => [
        'title' => 'Connect your AI assistant',
        'description' => 'Add TryPost as an MCP server so your assistant can create and manage social posts for you.',
        'copy' => 'Copy URL',
        'copied' => 'MCP URL copied.',
        'clients' => [
            'claude' => 'Open Settings → Connectors, add a custom connector, then paste the URL above.',
            'chatgpt' => 'Open Settings → Apps & Connectors, create a custom connector, then paste the URL above.',
        ],
    ],
    'social' => [
        'title' => 'Connect a social account',
        'description' => 'Choose at least one network where TryPost can publish your content.',
    ],
    'first_post' => [
        'title' => 'Create your first post',
        'description' => 'Try this starter prompt with your connected assistant, or create the post directly in TryPost.',
        'prompt_label' => 'Sample prompt',
        'sample_prompt' => 'Create a friendly social post introducing my brand and adapt it for each connected network.',
        'copy_prompt' => 'Copy prompt',
        'copied' => 'Sample prompt copied.',
        'create_button' => 'Create your first post',
    ],
    'ready' => [
        'title' => 'You are ready to publish',
        'description' => 'Your workspace is activated. Continue to TryPost and start planning your content.',
    ],
    'residual' => [
        'title' => 'Finish whenever you are ready',
        'description' => 'TryPost checks your progress automatically. Complete all three items to continue.',
    ],
];
