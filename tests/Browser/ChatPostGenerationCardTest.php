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
 * Instagram platform so a single format arrives listed twice. Both Instagram
 * accounts deliberately carry the SAME display name, which is what a brand
 * connected directly and through its Facebook Page actually looks like: only
 * the handle tells them apart.
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
    SocialAccount::factory()->for($workspace)->x()->create([
        'display_name' => 'Acme X',
        'username' => 'acmex',
    ]);

    SocialAccount::factory()->for($workspace)->instagram()->create([
        'display_name' => 'Acme',
        'username' => 'acme',
    ]);

    SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::InstagramFacebook,
        'display_name' => 'Acme',
        'username' => 'acme.business',
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
        'account' => 'Acme X (@acmex)',
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

    // Same display name on both connections: the handle is what distinguishes
    // them, and without it the two buttons would be indistinguishable.
    $page->assertSee('@acme.business')
        ->assertSee('@acme');

    $page->click("@chat-post-generation-account-{$instagramBusiness->id}");
    waitForChatTestId($page, 'chat-post-generation-submit');

    $page->click('@chat-post-generation-submit');

    // Instagram feed defaults to a single image, unlike every other format.
    $page->assertSee(__('chat.post_generation.sentence_with_brand', [
        'format' => __('posts.create.steps.format.instagram_feed'),
        'style' => __('posts.ai.templates.image_card.name'),
        'images' => __('chat.post_generation.sentence_images_one'),
        'account' => 'Acme (@acme.business)',
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
            'account' => 'Acme X (@acmex)',
            'brand' => __('chat.post_generation.sentence_brand_on'),
        ]));
});

test('a card the conversation already acted on reopens settled', function () {
    [$conversation] = chatWithPostGenerationCard();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_generate', 'name' => 'generate_post', 'arguments' => []]],
        'tool_results' => [['id' => 'call_generate', 'result' => '{"data":{"creation_id":"call_generate","channel":"c","settled":true}}']],
    ]);

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    // The choices were already sent, so the card must not offer to send them
    // again — a second submit would bill another generation.
    $page->assertSee(__('chat.post_generation.sent'))
        ->assertMissing('@chat-post-generation-submit');

    $disabled = $page->script(<<<'JS'
        (async () => document.querySelector('[data-testid="chat-post-generation-format-x_post"]').disabled)()
    JS);

    expect($disabled)->toBeTrue();
});

test('a workspace with one connected network opens straight on the styles', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::Threads,
        'display_name' => 'Acme Threads',
        'username' => 'acmethreads',
    ]);

    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick how you want it generated.',
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => []]],
        'tool_results' => [['id' => 'call_start', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    // One connected network is not a choice, so the card must not charge a
    // click for it — the style step is reachable without touching the format.
    waitForChatTestId($page, 'chat-post-generation-style-step');

    $page->assertVisible('@chat-post-generation-style-image_card')
        ->assertVisible('@chat-post-generation-style-step');

    $selected = $page->script(<<<'JS'
        (async () => document
            .querySelector('[data-testid="chat-post-generation-format-threads_post"]')
            .className.includes('border-foreground'))()
    JS);

    expect($selected)->toBeTrue();
});

test('revealing a step scrolls the thread to keep it in view', function () {
    [$conversation] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    // The app shell is `h-svh overflow-hidden`, so the window never scrolls —
    // the thread lives inside an overflow-y-auto ancestor. Find it the same
    // way the composable does, so this test fails if that assumption breaks.
    $scrolledToBottom = $page->script(<<<'JS'
        (async () => {
            const scroller = (() => {
                let node = document.querySelector('[data-testid="chat-thread"]')?.parentElement ?? null;
                while (node && node !== document.body) {
                    const { overflowY } = getComputedStyle(node);
                    if (overflowY === 'auto' || overflowY === 'scroll') return node;
                    node = node.parentElement;
                }
                return null;
            })();

            if (!scroller) return 'no-scroller';

            document.querySelector('[data-testid="chat-post-generation-format-x_post"]').click();
            await new Promise((r) => setTimeout(r, 400));
            document.querySelector('[data-testid="chat-post-generation-style-image_card"]').click();

            for (let i = 0; i < 60; i++) {
                await new Promise((r) => setTimeout(r, 50));
                if (scroller.scrollHeight - scroller.clientHeight - scroller.scrollTop <= 4) return true;
            }

            return `stuck: ${Math.round(scroller.scrollHeight - scroller.clientHeight - scroller.scrollTop)}px from bottom`;
        })()
    JS);

    expect($scrolledToBottom)->toBeTrue();
});

test('a stored turn renders its pre-tool text above the card and its answer below', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    seedGenerationAccounts($workspace);

    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => "Let me show you the formats.\n\nAll set, pick one above.",
        'parts' => [
            ['type' => 'text', 'text' => 'Let me show you the formats.'],
            ['type' => 'tool', 'id' => 'call_start', 'name' => 'start_post_generation'],
            ['type' => 'text', 'text' => 'All set, pick one above.'],
        ],
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => []]],
        'tool_results' => [['id' => 'call_start', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    // DOCUMENT_POSITION_PRECEDING is 2, DOCUMENT_POSITION_FOLLOWING is 4: the
    // announcement must come before the card it introduces, and the answer after.
    $order = $page->script(<<<'JS'
        (async () => {
            const card = document.querySelector('[data-testid="chat-post-generation-card"]');
            const find = (needle) => Array.from(document.querySelectorAll('.prose-chat'))
                .find((el) => el.textContent.includes(needle));

            const before = find('Let me show you the formats.');
            const after = find('All set, pick one above.');

            if (!card || !before || !after) return 'missing';

            return [
                card.compareDocumentPosition(before) & Node.DOCUMENT_POSITION_PRECEDING ? 'before' : 'not-before',
                card.compareDocumentPosition(after) & Node.DOCUMENT_POSITION_FOLLOWING ? 'after' : 'not-after',
            ].join('|');
        })()
    JS);

    expect($order)->toBe('before|after');
});

test('a turn stored without parts still renders its card and its text', function () {
    [$conversation] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    $page->assertSee('Pick how you want it generated.');

    $rendered = $page->script(<<<'JS'
        (async () => {
            const card = document.querySelector('[data-testid="chat-post-generation-card"]');
            const text = Array.from(document.querySelectorAll('.prose-chat'))
                .find((el) => el.textContent.includes('Pick how you want it generated.'));

            return Boolean(card && text);
        })()
    JS);

    expect($rendered)->toBeTrue();
});
