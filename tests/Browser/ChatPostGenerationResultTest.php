<?php

declare(strict_types=1);

use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForGenerationTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 160; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return true;
                await new Promise((r) => setTimeout(r, 50));
            }
            return false;
        })()
    JS);
}

/**
 * A conversation whose assistant turn called `generate_post`. The stored
 * result is exactly what the tool returns — a creation id and a channel, never
 * the post, because generation runs in the background.
 */
function chatWithGeneratedPost(string $creationId, Workspace $workspace, User $user, ?int $minutesAgo = null): WorkspaceConversation
{
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['data' => [
        'creation_id' => $creationId,
        'channel' => "user.{$user->id}.ai-creation.{$creationId}",
    ]]);

    $message = WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => $creationId, 'name' => 'generate_post', 'arguments' => ['prompt' => 'Our new pricing']]],
        'tool_results' => [['id' => $creationId, 'result' => $stored]],
    ]);

    if ($minutesAgo !== null) {
        $message->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();
    }

    return $conversation;
}

test('a conversation reopened after the generation finished renders the post itself', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    $account = SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    $post = Post::factory()->for($workspace)->create([
        'content' => 'Pricing just got simpler, and here is exactly what changed.',
        'creation_id' => 'call_done',
    ]);

    PostPlatform::factory()->for($post)->for($account)->create();

    $conversation = chatWithGeneratedPost('call_done', $workspace, $user);

    $page = visit(route('app.chat.show', $conversation));

    waitForGenerationTestId($page, 'chat-post-generation-result');

    // The post came from the server's creation_id lookup, so the card never
    // subscribed and never showed its waiting state.
    $page->assertPresent('@chat-post-card')
        ->assertMissing('@chat-post-generation-waiting')
        ->assertSee('Pricing just got simpler, and here is exactly what changed.')
        ->assertSee(__('chat.tool_card.open_in_editor'));
});

test('a generation with no post yet keeps waiting instead of claiming it failed', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    $conversation = chatWithGeneratedPost('call_pending', $workspace, $user);

    $page = visit(route('app.chat.show', $conversation));

    waitForGenerationTestId($page, 'chat-post-generation-waiting');

    $page->assertSee(__('chat.post_generation.result_waiting'))
        ->assertMissing('@chat-post-generation-failed')
        ->assertMissing('@chat-post-card');

    // The elapsed clock keeps ticking, which is the proof the card is alive
    // rather than frozen after its first render — the failure mode static
    // checks on this branch have already missed once.
    $ticked = $page->script(<<<'JS'
        (async () => {
            const clock = () => document.querySelector('[data-testid="chat-post-generation-waiting"] .font-mono')?.textContent ?? '';
            const first = clock();

            for (let i = 0; i < 80; i++) {
                const now = clock();

                if (now !== '' && now !== first) {
                    return true;
                }

                await new Promise((r) => setTimeout(r, 100));
            }

            return false;
        })()
    JS);

    expect($ticked)->toBeTrue('the waiting card should keep counting while it waits');
});

test('losing the broadcast channel is not reported as a failed generation', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    $conversation = chatWithGeneratedPost('call_detached', $workspace, $user);

    $page = visit(route('app.chat.show', $conversation));

    // No broadcast server answers here, so the subscription is refused — the
    // generation itself is untouched and the card must say so rather than
    // announcing a failure that never happened.
    waitForGenerationTestId($page, 'chat-post-generation-waiting-hint');

    $waitingHint = json_encode(__('chat.post_generation.result_waiting_hint'));

    $detached = $page->script(<<<JS
        (async () => {
            const hint = () => document.querySelector('[data-testid="chat-post-generation-waiting-hint"]')?.textContent?.trim() ?? '';

            for (let i = 0; i < 160; i++) {
                if (hint() !== '' && hint() !== {$waitingHint}) {
                    return hint();
                }

                await new Promise((r) => setTimeout(r, 50));
            }

            return hint();
        })()
    JS);

    expect($detached)->toBe(__('chat.post_generation.result_detached_hint'));

    $page->assertMissing('@chat-post-generation-failed');
});

test('a generation whose payload carries no channel shows the failure instead of waiting forever', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_broken', 'name' => 'generate_post', 'arguments' => []]],
        'tool_results' => [['id' => 'call_broken', 'result' => '{"data":{"creation_id":"call_broken"}}']],
    ]);

    $page = visit(route('app.chat.show', $conversation));

    waitForGenerationTestId($page, 'chat-post-generation-failed');

    $page->assertSee(__('chat.post_generation.result_failed'))
        ->assertMissing('@chat-post-generation-waiting');
});

test('a generation that ended long ago without a post never enters the waiting state', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    $conversation = chatWithGeneratedPost('call_stale', $workspace, $user, minutesAgo: 60);

    $page = visit(route('app.chat.show', $conversation));

    // The server marked the payload settled, so the card decides at mount:
    // no subscription, no spinner, and no sixteen-minute wait for its own
    // timeout to reach the same conclusion.
    waitForGenerationTestId($page, 'chat-post-generation-failed');

    $page->assertSee(__('chat.post_generation.result_failed'))
        ->assertMissing('@chat-post-generation-waiting')
        ->assertMissing('@chat-post-card');
});
