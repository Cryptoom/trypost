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

it('only ever offers formats drawn from its own allow-list', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);
    SocialAccount::factory()->for($workspace)->create(['platform' => 'pinterest']);

    $catalog = PostGenerationCatalog::forWorkspace($workspace);
    $values = collect($catalog['formats'])->pluck('value')->unique()->all();

    expect(array_diff($values, PostGenerationCatalog::allowedFormats()))->toBe([]);
});

it('reflects each template\'s needs_account requirement, not a hardcoded value', function (): void {
    $catalog = PostGenerationCatalog::forWorkspace(Workspace::factory()->create());
    $styles = collect($catalog['styles'])->keyBy('key');

    expect($styles['image_card']['needs_account'])->toBeFalse()
        ->and($styles['tweet_card']['needs_account'])->toBeTrue();
});

it('offers Instagram formats to a workspace connected only through Instagram Business', function (): void {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => 'instagram-facebook']);

    $catalog = PostGenerationCatalog::forWorkspace($workspace);
    $format = collect($catalog['formats'])->firstWhere('value', 'instagram_feed');

    expect($format)->not->toBeNull()
        ->and($format['platform'])->toBe('instagram-facebook')
        ->and(collect($format['accounts'])->pluck('id')->all())->toBe([$account->id]);
});

it('never offers a carousel the generation pipeline cannot produce', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'pinterest']);

    $catalog = PostGenerationCatalog::forWorkspace($workspace);
    $values = collect($catalog['formats'])->pluck('value')->all();

    expect($values)->toContain('pinterest_pin')
        ->and($values)->not->toContain('pinterest_carousel');
});

it('tells two Instagram connections apart when they share a display name', function (): void {
    $workspace = Workspace::factory()->create();

    $direct = SocialAccount::factory()->for($workspace)->create([
        'platform' => 'instagram',
        'display_name' => 'Acme',
        'username' => 'acme',
    ]);

    $business = SocialAccount::factory()->for($workspace)->create([
        'platform' => 'instagram-facebook',
        'display_name' => 'Acme',
        'username' => 'acme.business',
    ]);

    $accounts = collect(PostGenerationCatalog::forWorkspace($workspace)['formats'])
        ->where('value', 'instagram_feed')
        ->flatMap(fn (array $format): array => $format['accounts'])
        ->keyBy('id');

    expect($accounts)->toHaveCount(2)
        ->and($accounts[$direct->id]['label'])->toBe($accounts[$business->id]['label'])
        ->and($accounts[$direct->id]['username'])->toBe('acme')
        ->and($accounts[$business->id]['username'])->toBe('acme.business')
        ->and($accounts[$direct->id]['platform'])->toBe('instagram')
        ->and($accounts[$business->id]['platform'])->toBe('instagram-facebook');
});
