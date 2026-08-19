<?php

declare(strict_types=1);

use App\Ai\Tools\Post\StartPostGenerationTool;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Ai\Tools\Request;

it('returns the workspace catalog', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))->handle(new Request([])),
        true,
    );

    expect($output['data']['formats'])->not->toBeEmpty()
        ->and($output['data']['styles'])->not->toBeEmpty();
});

it('is named start_post_generation', function (): void {
    expect((new StartPostGenerationTool(Workspace::factory()->create(), User::factory()->create()))->name())
        ->toBe('start_post_generation');
});
