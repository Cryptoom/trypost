<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Ai\PostGenerationCatalog;

it('offers only formats whose platform has a connected account', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $catalog = PostGenerationCatalog::forWorkspace($workspace);
    $platforms = collect($catalog['formats'])->pluck('platform')->unique()->all();

    expect($platforms)->toBe(['threads']);
});

it('lists the accounts available for each format', function (): void {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $catalog = PostGenerationCatalog::forWorkspace($workspace);
    $format = collect($catalog['formats'])->firstWhere('platform', 'threads');

    expect(collect($format['accounts'])->pluck('id')->all())->toBe([$account->id]);
});

it('returns an empty format list when nothing is connected', function (): void {
    $catalog = PostGenerationCatalog::forWorkspace(Workspace::factory()->create());

    expect($catalog['formats'])->toBe([]);
});

it('never leaks another workspace accounts', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);
    SocialAccount::factory()->for(Workspace::factory())->create(['platform' => 'threads']);

    $catalog = PostGenerationCatalog::forWorkspace($workspace);
    $format = collect($catalog['formats'])->firstWhere('platform', 'threads');

    expect($format['accounts'])->toHaveCount(1);
});
