<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForChatTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 160; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

/**
 * Stop the composer's turn from leaving the browser: submitting the card
 * sends an ordinary user message, and this test is about what that message
 * says, not about the model answering it.
 */
function stubChatTurn(mixed $page): void
{
    $page->script(<<<'JS'
        window.fetch = async () => new Response(JSON.stringify({ message: 'stubbed' }), {
            status: 402,
            headers: { 'Content-Type': 'application/json' },
        });
    JS);
}

/**
 * A conversation whose assistant turn called `start_post_generation`, with
 * three active accounts behind it — one X account, and one account on each
 * Instagram platform so a single format arrives listed twice.
 *
 * `start_post_generation` is replayable, so opening the page re-runs it and
 * the card renders the workspace's CURRENT catalog; the stored result below
 * is only the fallback for a replay that errors.
 *
 * @return array{0: WorkspaceConversation, 1: SocialAccount}
 */
function chatWithPostGenerationCard(): array
{
    [$user, $workspace] = actingAsWorkspaceUser();

    seedGenerationAccounts($workspace);

    $instagramBusiness = SocialAccount::query()
        ->where('workspace_id', $workspace->id)
        ->where('platform', Platform::InstagramFacebook)
        ->firstOrFail();

    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick how you want it generated.',
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => []]],
        'tool_results' => [['id' => 'call_start', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    return [$conversation, $instagramBusiness];
}

function seedGenerationAccounts(Workspace $workspace): void
{
    SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);
    SocialAccount::factory()->for($workspace)->instagram()->create(['display_name' => 'Acme IG']);
    SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::InstagramFacebook,
        'display_name' => 'Acme Business',
    ]);
}

test('the card reveals its choices and submits them as one sentence', function () {
    [$conversation] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');
    stubChatTurn($page);

    // Nothing past the format list is offered yet.
    $page->assertDontSee(__('chat.post_generation.style_label'))
        ->assertDontSee(__('chat.post_generation.images_label'));

    $page->click('@chat-post-generation-format-x_post');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    // A single-account format never asks which account to post as.
    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-images-step');

    $page->assertDontSee(__('chat.post_generation.account_label'))
        ->assertSee(__('chat.post_generation.brand_colors_label'));

    $page->click('@chat-post-generation-submit');

    $page->assertSee(__('chat.post_generation.sentence_with_brand', [
        'format' => __('posts.create.steps.format.x_post'),
        'style' => __('posts.ai.templates.image_card.name'),
        'images' => __('chat.post_generation.sentence_images_other', ['count' => 2]),
        'account' => 'Acme X',
        'brand' => __('chat.post_generation.sentence_brand_on'),
    ]));
});

test('a format connected on two platforms is offered once with both accounts', function () {
    [$conversation, $instagramBusiness] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');
    stubChatTurn($page);

    $page->click('@chat-post-generation-format-instagram_feed');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-account-step');

    $page->assertSee('Acme IG')
        ->assertSee('Acme Business');

    $page->click("@chat-post-generation-account-{$instagramBusiness->id}");
    waitForChatTestId($page, 'chat-post-generation-submit');

    $page->click('@chat-post-generation-submit');

    // Instagram feed defaults to a single image, unlike every other format.
    $page->assertSee(__('chat.post_generation.sentence_with_brand', [
        'format' => __('posts.create.steps.format.instagram_feed'),
        'style' => __('posts.ai.templates.image_card.name'),
        'images' => __('chat.post_generation.sentence_images_one'),
        'account' => 'Acme Business',
        'brand' => __('chat.post_generation.sentence_brand_on'),
    ]));
});

test('the card refuses to submit while a turn is still streaming', function () {
    [$conversation] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    $page->click('@chat-post-generation-format-x_post');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-submit');

    $page->script(<<<'JS'
        (async () => {
            window.fetch = () => new Promise(() => {});

            return true;
        })()
    JS);

    $page->fill('@chat-composer-input', 'hold on');
    $page->click('@chat-send');

    $busy = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 160; i++) {
                const el = document.querySelector('[data-testid="chat-post-generation-submit"]');

                if (el && el.disabled) {
                    return true;
                }

                await new Promise((r) => setTimeout(r, 50));
            }

            return false;
        })()
    JS);

    expect($busy)->toBeTrue('the card should disable its submit button while a turn is in flight');

    $page->script(<<<'JS'
        (async () => {
            document.querySelector('[data-testid="chat-post-generation-submit"]').click();

            return true;
        })()
    JS);

    // Neither latched into its sent state nor sent as a message.
    $page->assertMissing('@chat-post-generation-sent')
        ->assertDontSee(__('chat.post_generation.sentence_with_brand', [
            'format' => __('posts.create.steps.format.x_post'),
            'style' => __('posts.ai.templates.image_card.name'),
            'images' => __('chat.post_generation.sentence_images_other', ['count' => 2]),
            'account' => 'Acme X',
            'brand' => __('chat.post_generation.sentence_brand_on'),
        ]));
});
