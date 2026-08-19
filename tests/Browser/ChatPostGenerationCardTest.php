<?php

declare(strict_types=1);

use App\Enums\WorkspaceConversation\Message\Role;
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
 * The catalog `start_post_generation` returns, stored as the call's result:
 * the tool is not in `ToolReplayer::REPLAYABLE`, so a reopened conversation
 * renders exactly this payload.
 *
 * `instagram_feed` is listed twice — once per Instagram platform — which is
 * what the real catalog does when both are connected.
 */
function postGenerationCatalog(): string
{
    return json_encode([
        'data' => [
            'formats' => [
                [
                    'value' => 'instagram_feed',
                    'platform' => 'instagram',
                    'accounts' => [['id' => 'acc-ig', 'label' => 'Acme IG']],
                ],
                [
                    'value' => 'instagram_feed',
                    'platform' => 'instagram-facebook',
                    'accounts' => [['id' => 'acc-ig-fb', 'label' => 'Acme Business']],
                ],
                [
                    'value' => 'x_post',
                    'platform' => 'x',
                    'accounts' => [['id' => 'acc-x', 'label' => 'Acme X']],
                ],
            ],
            'styles' => [
                [
                    'key' => 'image_card',
                    'name' => 'Image card',
                    'description' => 'A generated illustration.',
                    'preview' => '/images/trypost/icon.png',
                    'needs_account' => false,
                    'supported_formats' => [],
                    'applies_brand_visuals' => true,
                ],
                [
                    'key' => 'tweet_card',
                    'name' => 'Tweet card',
                    'description' => 'The post rendered as your own card.',
                    'preview' => '/images/trypost/icon.png',
                    'needs_account' => true,
                    'supported_formats' => [],
                    'applies_brand_visuals' => true,
                ],
            ],
            'applies_brand_visuals_default' => true,
        ],
    ], JSON_THROW_ON_ERROR);
}

function chatWithPostGenerationCard(): WorkspaceConversation
{
    [$user, $workspace] = actingAsWorkspaceUser();

    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick how you want it generated.',
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => []]],
        'tool_results' => [['id' => 'call_start', 'result' => postGenerationCatalog()]],
    ]);

    return $conversation;
}

test('the card reveals its choices and submits them as one sentence', function () {
    $conversation = chatWithPostGenerationCard();

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
        'style' => 'Image card',
        'images' => __('chat.post_generation.sentence_images_other', ['count' => 2]),
        'account' => 'Acme X',
        'brand' => __('chat.post_generation.sentence_brand_on'),
    ]));
});

test('a format connected on two platforms is offered once with both accounts', function () {
    $conversation = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');
    stubChatTurn($page);

    $page->click('@chat-post-generation-format-instagram_feed');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-account-step');

    $page->assertSee('Acme IG')
        ->assertSee('Acme Business');

    $page->click('@chat-post-generation-account-acc-ig-fb');
    waitForChatTestId($page, 'chat-post-generation-submit');

    $page->click('@chat-post-generation-submit');

    // Instagram feed defaults to a single image, unlike every other format.
    $page->assertSee(__('chat.post_generation.sentence_with_brand', [
        'format' => __('posts.create.steps.format.instagram_feed'),
        'style' => 'Image card',
        'images' => __('chat.post_generation.sentence_images_one'),
        'account' => 'Acme Business',
        'brand' => __('chat.post_generation.sentence_brand_on'),
    ]));
});
