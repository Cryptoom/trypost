<?php

declare(strict_types=1);

return [
    'title' => 'Getting started',
    'welcome' => 'Welcome to TryPost, :name',
    'description' => 'Follow the steps below to see how TryPost works and publish your first post.',
    'skip' => 'Skip for now',
    'continue' => 'Continue to TryPost',
    'progress' => ':done of :total complete',
    'progress_label' => 'Setup progress',
    'next_step' => 'Up next',
    'step_label' => 'Step :number',
    'status' => [
        'complete' => 'Complete',
        'todo' => 'To do',
    ],
    'mcp' => [
        'title' => 'Connect your AI assistant',
        'description' => 'Add TryPost as an MCP server so your assistant can create and manage social posts for you.',
        'copy_step' => 'Copy your TryPost server URL',
        'open_step' => 'Open your AI assistant',
        'open_hint' => 'Choose one. Its settings page opens in a new tab.',
        'url_label' => 'MCP server URL',
        'copy' => 'Copy URL',
        'copied' => 'MCP URL copied.',
        'guide' => 'Open settings',
        'connect' => 'Connect with :client',
        'clients' => [
            'claude' => 'Open Settings → Connectors, add a custom connector, then paste the URL above.',
            'chatgpt' => 'Open Settings → Apps & Connectors, create a custom connector, then paste the URL above.',
        ],
    ],
    'social' => [
        'title' => 'Connect a social account',
        'description' => 'Choose at least one network where TryPost can publish your content.',
        'action' => 'Choose where you want to publish',
        'action_hint' => 'Connect at least one account. You can add more whenever you want.',
    ],
    'first_post' => [
        'title' => 'Create your first post',
        'description' => 'Try this starter prompt with your connected assistant, or create the post directly in TryPost.',
        'action' => 'Create a post and see TryPost in action',
        'action_hint' => 'Send this prompt to your assistant, or open the TryPost editor.',
        'prompt_label' => 'Sample prompt',
        'sample_prompt' => 'Create a friendly social post introducing my brand and adapt it for each connected network.',
        'copy_prompt' => 'Copy prompt',
        'copied' => 'Sample prompt copied.',
        'create_button' => 'Create your first post',
        'or' => 'or',
    ],
    'ready' => [
        'title' => 'You are ready to publish',
        'description' => 'You are set. Continue to TryPost and start planning your content.',
    ],
];
