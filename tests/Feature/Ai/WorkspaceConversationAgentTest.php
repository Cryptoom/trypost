<?php

declare(strict_types=1);

use App\Ai\Agents\WorkspaceConversationAgent;
use App\Ai\Tools\Post\ListPostsTool;
use App\Models\User;
use App\Models\Workspace;

test('the agent exposes the eight post tools', function () {
    $agent = new WorkspaceConversationAgent(Workspace::factory()->create(), User::factory()->create());

    expect(collect($agent->tools())->count())->toBe(8)
        ->and(collect($agent->tools())->first())->toBeInstanceOf(ListPostsTool::class);
});

test('the instructions carry the workspace brand and content language', function () {
    $workspace = Workspace::factory()->create([
        'name' => 'Acme Co',
        'brand_description' => 'We sell anvils.',
    ]);

    $instructions = (new WorkspaceConversationAgent($workspace, User::factory()->create()))->instructions();

    expect($instructions)->toContain('Acme Co')
        ->and($instructions)->toContain('We sell anvils.');
});
