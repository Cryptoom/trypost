<?php

declare(strict_types=1);

use App\Ai\Tools\Post\StartPostGenerationTool;
use App\Enums\Workspace\ContentLanguage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\PostGenerationCatalog;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
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

it('resolves every string it returns in the language the model reported', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))
            ->handle(new Request(['language' => 'pt-BR'])),
        true,
    );

    // The interface is still in English here: only the payload follows the
    // conversation, which is the whole point of the argument.
    expect(app()->getLocale())->toBe('en')
        ->and(__('chat.post_generation.format_question', [], 'pt-BR'))
        ->not->toBe(__('chat.post_generation.format_question', [], 'en'))
        ->and($output['data']['locale'])->toBe('pt-BR')
        ->and($output['data']['copy']['format_question'])->toBe(__('chat.post_generation.format_question', [], 'pt-BR'))
        ->and($output['data']['copy']['sentence'])->toBe(__('chat.post_generation.sentence', [], 'pt-BR'))
        ->and($output['data']['copy']['change'])->toBe(__('chat.post_generation.change', [], 'pt-BR'))
        ->and($output['data']['formats'][0]['label'])->toBe(__('posts.create.steps.format.threads_post', [], 'pt-BR'))
        ->and(collect($output['data']['styles'])->firstWhere('key', 'tweet_card')['name'])
        ->toBe(__('posts.ai.templates.tweet_card.name', [], 'pt-BR'));
});

it('matches the reported language whatever case the model wrote it in', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))
            ->handle(new Request(['language' => 'PT-br'])),
        true,
    );

    expect($output['data']['locale'])->toBe('pt-BR');
});

it('falls back to the app locale when the model reported no language', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))->handle(new Request([])),
        true,
    );

    expect($output['data']['locale'])->toBe('en')
        ->and($output['data']['copy']['format_question'])->toBe(__('chat.post_generation.format_question', [], 'en'))
        ->and($output['data']['formats'][0]['label'])->toBe(__('posts.create.steps.format.threads_post', [], 'en'));
});

it('falls back to the app locale for a language this app does not ship', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))
            ->handle(new Request(['language' => 'sv'])),
        true,
    );

    expect($output['data']['locale'])->toBe('en')
        ->and($output['data']['copy']['format_question'])->toBe(__('chat.post_generation.format_question', [], 'en'));
});

it('returns the format the user named when the workspace can post it', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))
            ->handle(new Request(['format' => ' threads_post '])),
        true,
    );

    expect($output['data']['format'])->toBe('threads_post');
});

it('drops a format the workspace has no connected account for', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    // Nothing is refused: the card simply asks, exactly as it does when the
    // user named no format at all.
    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))
            ->handle(new Request(['format' => 'linkedin_post'])),
        true,
    );

    expect($output['data']['format'])->toBeNull()
        ->and($output['data']['formats'])->not->toBeEmpty();
});

it('drops a format that is not a format at all', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))
            ->handle(new Request(['format' => 'carrier_pigeon'])),
        true,
    );

    expect($output['data']['format'])->toBeNull();
});

it('returns no format when the model named none', function (): void {
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->for($workspace)->create(['platform' => 'threads']);

    $output = json_decode(
        (new StartPostGenerationTool($workspace, User::factory()->create()))->handle(new Request([])),
        true,
    );

    expect($output['data']['format'])->toBeNull();
});

it('offers the model only formats this application can generate', function (): void {
    $schema = (new StartPostGenerationTool(Workspace::factory()->create(), User::factory()->create()))
        ->schema(new JsonSchemaTypeFactory);

    expect($schema['format']->toArray()['enum'])->toBe(PostGenerationCatalog::formatValues())
        ->and($schema['language']->toArray()['enum'])->toBe(ContentLanguage::values());
});
