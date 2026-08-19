<?php

declare(strict_types=1);

use App\Ai\Tools\Post\GeneratePostTool;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Events\Ai\PostCreationReady;
use App\Jobs\Ai\StreamPostCreation;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Bus::fake();

    [$this->user, $this->workspace] = workspaceUserWithRole(Role::Member);

    $this->account = SocialAccount::factory()->for($this->workspace)->create([
        'platform' => Platform::Threads,
    ]);

    $this->tool = new GeneratePostTool($this->workspace, $this->user);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generatePostPayload(array $overrides = []): array
{
    return array_merge([
        'prompt' => 'Write a post about our new pricing page',
        'format' => 'threads_post',
        'style' => 'image_card',
        'image_count' => 1,
        'social_account_id' => test()->account->id,
        'apply_brand_visuals' => true,
    ], $overrides);
}

it('is named generate_post', function (): void {
    expect($this->tool->name())->toBe('generate_post');
});

it('dispatches the generation job and returns immediately', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload())), true);

    expect($output['data']['creation_id'])->not->toBeEmpty()
        ->and($output['data']['channel'])->toContain($output['data']['creation_id']);

    Bus::assertDispatched(StreamPostCreation::class, function (StreamPostCreation $job) use ($output): bool {
        return $job->creationId === $output['data']['creation_id']
            && $job->userId === $this->user->id
            && $job->workspaceId === $this->workspace->id
            && $job->format === 'threads_post'
            && $job->template === 'image_card'
            && $job->socialAccountId === $this->account->id
            && $job->imageCount === 1
            && $job->prompt === 'Write a post about our new pricing page'
            && $job->date === null
            && $job->applyBrandVisuals === true;
    });
});

it('returns the same channel PostCreationReady broadcasts on', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload())), true);

    $creationId = $output['data']['creation_id'];

    expect($output['data']['channel'])
        ->toBe("user.{$this->user->id}.ai-creation.{$creationId}")
        ->and((new PostCreationReady($this->user->id, $creationId))->broadcastOn()->name)
        ->toBe("private-{$output['data']['channel']}");
});

it('mints a unique creation id per call when the provider supplies no tool call id', function (): void {
    $first = json_decode($this->tool->handle(new Request(generatePostPayload())), true);
    $second = json_decode($this->tool->handle(new Request(generatePostPayload())), true);

    expect($first['data']['creation_id'])->not->toBe($second['data']['creation_id']);

    Bus::assertDispatchedTimes(StreamPostCreation::class, 2);
});

it('reuses the tool call id as the creation id so a retry hits the job uniqueness lock', function (): void {
    $first = json_decode($this->tool->handle(new Request(generatePostPayload(), 'call_retried_once')), true);
    $second = json_decode($this->tool->handle(new Request(generatePostPayload(), 'call_retried_once')), true);

    expect($first['data']['creation_id'])->toBe('call_retried_once')
        ->and($second['data']['creation_id'])->toBe($first['data']['creation_id'])
        ->and($second['data']['channel'])->toBe($first['data']['channel']);

    Bus::assertDispatched(StreamPostCreation::class, function (StreamPostCreation $job): bool {
        return $job->uniqueId() === "{$this->user->id}:call_retried_once";
    });
});

it('gives two distinct tool calls two distinct creation ids', function (): void {
    $first = json_decode($this->tool->handle(new Request(generatePostPayload(), 'call_one')), true);
    $second = json_decode($this->tool->handle(new Request(generatePostPayload(), 'call_two')), true);

    expect($first['data']['creation_id'])->toBe('call_one')
        ->and($second['data']['creation_id'])->toBe('call_two');

    Bus::assertDispatchedTimes(StreamPostCreation::class, 2);
});

it('defaults the optional arguments the way the create wizard did', function (): void {
    $this->tool->handle(new Request([
        'prompt' => 'A post about coffee',
        'format' => 'threads_post',
        'style' => 'image_card',
    ]));

    Bus::assertDispatched(StreamPostCreation::class, function (StreamPostCreation $job): bool {
        return $job->imageCount === 0
            && $job->date === null
            && $job->socialAccountId === null
            && $job->applyBrandVisuals === true;
    });
});

it('carries the date and the brand visuals choice through to the job', function (): void {
    $this->tool->handle(new Request(generatePostPayload([
        'date' => '2026-06-15',
        'apply_brand_visuals' => false,
    ])));

    Bus::assertDispatched(StreamPostCreation::class, function (StreamPostCreation $job): bool {
        return $job->date === '2026-06-15' && $job->applyBrandVisuals === false;
    });
});

it('refuses a social account from another workspace', function (): void {
    $foreign = SocialAccount::factory()->for(Workspace::factory())->create([
        'platform' => Platform::Threads,
    ]);

    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'social_account_id' => $foreign->id,
    ]))), true);

    expect($output)->toHaveKey('error')
        ->and($output['error'])->toContain('social account');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('refuses a deactivated account even though the workspace owns it', function (): void {
    $deactivated = SocialAccount::factory()->for($this->workspace)->create([
        'platform' => Platform::Threads,
        'is_active' => false,
    ]);

    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'social_account_id' => $deactivated->id,
    ]))), true);

    expect($output)->toHaveKey('error')
        ->and($output['error'])->toContain($deactivated->id)
        ->and($output['error'])->toContain($this->account->id);

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('refuses an account whose platform cannot post the chosen format', function (): void {
    $linkedin = SocialAccount::factory()->for($this->workspace)->create([
        'platform' => Platform::LinkedIn,
    ]);

    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'social_account_id' => $linkedin->id,
    ]))), true);

    expect($output)->toHaveKey('error')
        ->and($output['error'])->toContain('threads_post')
        ->and($output['error'])->toContain($this->account->id);

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('refuses a format with no connected account', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'format' => 'x_post',
        'social_account_id' => null,
    ]))), true);

    expect($output)->toHaveKey('error')
        ->and($output['error'])->toContain('x_post')
        ->and($output['error'])->toContain('threads_post');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('refuses a format that does not exist at all', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'format' => 'myspace_post',
    ]))), true);

    expect($output)->toHaveKey('error')
        ->and($output['error'])->toContain('myspace_post');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('refuses a format when the workspace has no connected accounts at all', function (): void {
    $this->account->delete();

    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'social_account_id' => null,
    ]))), true);

    expect($output)->toHaveKey('error')
        ->and($output['error'])->toContain('connect');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('refuses an unknown style and names the valid ones', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'style' => 'bogus',
    ]))), true);

    expect($output)->toHaveKey('error')
        ->and($output['error'])->toContain('bogus')
        ->and($output['error'])->toContain('image_card')
        ->and($output['error'])->toContain('tweet_card');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('refuses a style that needs an account when none was given', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'style' => 'tweet_card',
        'social_account_id' => null,
    ]))), true);

    expect($output)->toHaveKey('error')
        ->and($output['error'])->toContain('tweet_card')
        ->and($output['error'])->toContain('social_account_id');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('allows a style that needs an account when one was given', function (): void {
    $this->tool->handle(new Request(generatePostPayload(['style' => 'tweet_card'])));

    Bus::assertDispatched(StreamPostCreation::class, fn (StreamPostCreation $job): bool => $job->template === 'tweet_card');
});

it('rejects a prompt shorter than the shared minimum', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'prompt' => str_repeat('a', AiPromptRules::PROMPT_MIN_LENGTH - 1),
    ]))), true);

    expect($output)->toHaveKey('error');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('rejects a prompt longer than the shared maximum', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'prompt' => str_repeat('a', AiPromptRules::PROMPT_MAX_LENGTH + 1),
    ]))), true);

    expect($output)->toHaveKey('error');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('rejects a missing prompt', function (): void {
    $output = json_decode($this->tool->handle(new Request([
        'format' => 'threads_post',
        'style' => 'image_card',
    ])), true);

    expect($output)->toHaveKey('error');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('rejects an image count outside the allowed range', function (mixed $imageCount): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'image_count' => $imageCount,
    ]))), true);

    expect($output)->toHaveKey('error');

    Bus::assertNotDispatched(StreamPostCreation::class);
})->with([
    'negative' => [-1],
    'above the maximum' => [11],
]);

it('rejects a date that is not Y-m-d', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'date' => 'next tuesday',
    ]))), true);

    expect($output)->toHaveKey('error');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('rejects a social account id that is not a uuid', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'social_account_id' => 'not-a-uuid',
    ]))), true);

    expect($output)->toHaveKey('error');

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('refuses when the account may not use AI', function (): void {
    config()->set('trypost.self_hosted', false);

    $output = json_decode($this->tool->handle(new Request(generatePostPayload())), true);

    expect($output)->toBe(['error' => __('billing.flash.subscription_required')]);

    Bus::assertNotDispatched(StreamPostCreation::class);
});

it('never trusts a workspace id supplied by the model', function (): void {
    $other = Workspace::factory()->create();

    $output = json_decode($this->tool->handle(new Request(generatePostPayload([
        'workspace_id' => $other->id,
    ]))), true);

    Bus::assertDispatched(StreamPostCreation::class, fn (StreamPostCreation $job): bool => $job->workspaceId === $this->workspace->id);

    expect($output['data']['creation_id'])->toBeString();
});

it('returns a uuid creation id', function (): void {
    $output = json_decode($this->tool->handle(new Request(generatePostPayload())), true);

    expect(Str::isUuid($output['data']['creation_id']))->toBeTrue();
});
