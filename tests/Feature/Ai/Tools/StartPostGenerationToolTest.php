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

it('returns the topic the model extracted, trimmed', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))
            ->handle(new Request(['topic' => '  the X launch  '])),
        true,
    );

    expect($output['data']['topic'])->toBe('the X launch');
});

it('returns an empty topic when the model did not pass one', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    // The card asks with a blank field rather than a made-up subject.
    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))->handle(new Request([])),
        true,
    );

    expect($output['data']['topic'])->toBe('');
});

it('is named start_post_generation', function (): void {
    expect((new StartPostGenerationTool(Workspace::factory()->create(), User::factory()->create()))->name())
        ->toBe('start_post_generation');
});
