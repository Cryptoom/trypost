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
function chatWithPostGenerationCard(int $priorMessages = 0): array
{
    [$user, $workspace] = actingAsWorkspaceUser();

    seedGenerationAccounts($workspace);

    $instagramBusiness = SocialAccount::query()
        ->where('workspace_id', $workspace->id)
        ->where('platform', Platform::InstagramFacebook)
        ->firstOrFail();

    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    for ($index = 0; $index < $priorMessages; $index++) {
        WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
            'role' => $index % 2 === 0 ? Role::User : Role::Assistant,
            'content' => "Earlier turn {$index}. ".str_repeat('This conversation already ran for a while. ', 6),
            'created_at' => now()->subMinutes($priorMessages - $index + 1),
        ]);
    }

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

    // Nothing past the format question is offered yet.
    $page->assertMissing('@chat-post-generation-style-step')
        ->assertMissing('@chat-post-generation-images-step');

    $page->click('@chat-post-generation-format-x_post');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    // The answered step reads back as the user's own message, and the question
    // that replaced it is gone.
    $page->assertVisible('@chat-post-generation-format-choice')
        ->assertMissing('@chat-post-generation-format-step')
        ->assertSee(__('posts.create.steps.format.x_post'));

    // A single-account format never asks which account to post as.
    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-images-step');

    $page->assertMissing('@chat-post-generation-account-step')
        ->assertMissing('@chat-post-generation-account-choice')
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
    waitForChatTestId($page, 'chat-post-generation-account-choice');

    // The chosen account replaces its question with the user's own message.
    $page->assertMissing('@chat-post-generation-account-step')
        ->assertSee('Acme (@acme.business)');

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
    // again — a second submit would bill another generation. It collapses to
    // one compact record instead of leaving five dead blocks in the thread.
    $page->assertSee(__('chat.post_generation.sent'))
        ->assertMissing('@chat-post-generation-submit')
        ->assertMissing('@chat-post-generation-format-step')
        ->assertMissing('@chat-post-generation-format-x_post')
        ->assertMissing('@chat-post-generation-style-step');

    $interactive = $page->script(<<<'JS'
        (async () => document
            .querySelector('[data-testid="chat-post-generation-card"]')
            .querySelectorAll('button, input, [role="switch"]').length)()
    JS);

    expect($interactive)->toBe(0);
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

    // The format still opens the thread, as the choice the card made on the
    // user's behalf — and with nothing to switch to, it offers no way back.
    $page->assertVisible('@chat-post-generation-format-choice')
        ->assertSee(__('posts.create.steps.format.threads_post'))
        ->assertMissing('@chat-post-generation-format-step')
        ->assertMissing('@chat-post-generation-format-choice-change');
});

/**
 * The kind of every block the card currently has, in DOM order, plus whether
 * any open question sits above an answered step. A question block is an
 * assistant bubble (it carries the assistant avatar), a record is the user's
 * own message for an answered step, and the final block is the panel that
 * carries the defaults and the submit button.
 */
function chatCardBlocks(mixed $page): string
{
    return (string) $page->script(<<<'JS'
        (async () => {
            const root = document.querySelector('[data-testid="chat-post-generation-card"]');

            if (!root) return 'missing';

            const kinds = Array.from(root.children).map((el) => {
                if (el.matches('[data-testid="chat-post-generation-final"]')) return 'final';
                if (el.querySelector('[data-testid$="-choice"]')) return 'record';
                if (el.querySelector('img[src="/images/trypost/icon.png"]')) return 'question';
                return 'other';
            });

            const answeredAfterQuestion = kinds.some(
                (kind, index) => kind === 'question'
                    && kinds.slice(index + 1).some((later) => later === 'record' || later === 'final'),
            );

            return `${kinds.join(',')}|${answeredAfterQuestion ? 'question-above-answered' : 'ordered'}`;
        })()
    JS);
}

test('the card never leaves an open question above an answered step', function () {
    [$conversation, $instagramBusiness] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    $page->click('@chat-post-generation-format-instagram_feed');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-account-step');

    // Mid-flow: the two answered steps read back as the user's own messages,
    // and the only open question is the last block in the thread.
    expect(chatCardBlocks($page))->toBe('record,record,question|ordered');

    $page->click("@chat-post-generation-account-{$instagramBusiness->id}");
    waitForChatTestId($page, 'chat-post-generation-final');

    // Answered: nothing is left open above the final block, which is where the
    // image count and the brand toggle live — next to the button that acts on
    // them, rather than as a question the conversation walked past.
    expect(chatCardBlocks($page))->toBe('record,record,record,final|ordered');

    $page->assertVisible('@chat-post-generation-images-step')
        ->assertVisible('@chat-post-generation-brand-step')
        ->assertVisible('@chat-post-generation-submit');
});

test('an account the card picked itself is never recorded as the user\'s choice', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    // One network, one account — the cloud default, since
    // App\Observers\SocialAccountObserver::creating allows a single account
    // per network per workspace outside self-hosted.
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
    stubChatTurn($page);

    // tweet_card has needs_account, which is what used to force an account
    // block for a question the card never asked.
    $page->click('@chat-post-generation-style-tweet_card');
    waitForChatTestId($page, 'chat-post-generation-final');

    $page->assertMissing('@chat-post-generation-account-step')
        ->assertMissing('@chat-post-generation-account-choice')
        ->assertVisible('@chat-post-generation-account-auto')
        ->assertMissing('@chat-post-generation-brand-step')
        ->assertSee(__('chat.post_generation.posting_to', ['account' => 'Acme Threads (@acmethreads)']));

    expect(chatCardBlocks($page))->toBe('record,record,final|ordered');

    // The account the card picked still reaches the sentence.
    $page->click('@chat-post-generation-submit');

    // tweet_card renders the post as that account's own card and applies no
    // brand visuals, so the sentence carries no brand clause.
    $page->assertSee(__('chat.post_generation.sentence', [
        'format' => __('posts.create.steps.format.threads_post'),
        'style' => __('posts.ai.templates.tweet_card.name'),
        'images' => __('chat.post_generation.sentence_images_other', ['count' => 2]),
        'account' => 'Acme Threads (@acmethreads)',
    ]));
});

test('changing one step after reopening another keeps the reopened question open', function () {
    [$conversation, $instagramBusiness] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    $page->click('@chat-post-generation-format-instagram_feed');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-account-step');

    $page->click("@chat-post-generation-account-{$instagramBusiness->id}");
    waitForChatTestId($page, 'chat-post-generation-account-choice');

    // Reopen the account, then change the style instead of answering it.
    $page->click('@chat-post-generation-account-choice-change');
    waitForChatTestId($page, 'chat-post-generation-account-step');

    $page->assertVisible('@chat-post-generation-account-step')
        ->assertMissing('@chat-post-generation-account-choice');

    $page->click('@chat-post-generation-style-choice-change');
    waitForChatTestId($page, 'chat-post-generation-style-step');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-account-step');

    // The account the user had just reopened must not come back answered
    // behind their back.
    $page->assertVisible('@chat-post-generation-account-step')
        ->assertMissing('@chat-post-generation-account-choice')
        ->assertMissing('@chat-post-generation-final');

    expect(chatCardBlocks($page))->toBe('record,record,question|ordered');
});

test('a recorded choice can be reopened and changed', function () {
    [$conversation] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    $page->click('@chat-post-generation-format-x_post');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    // A recorded choice is not a dead end: reopening it brings its question
    // back and drops what the old answer had already revealed.
    $page->click('@chat-post-generation-format-choice-change');
    waitForChatTestId($page, 'chat-post-generation-format-step');

    $page->assertMissing('@chat-post-generation-format-choice');

    $page->click('@chat-post-generation-format-instagram_feed');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->assertSee(__('posts.create.steps.format.instagram_feed'))
        ->assertDontSee(__('posts.create.steps.format.x_post'));
});

test('revealing a step scrolls the thread to keep it in view', function () {
    // Enough history that the thread genuinely overflows: with a short one the
    // scroller can sit at scrollHeight === clientHeight, where "scrolled to the
    // bottom" is true without anything having scrolled at all.
    [$conversation] = chatWithPostGenerationCard(14);

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

            const overflow = scroller.scrollHeight - scroller.clientHeight;

            if (overflow < 400) return `not-overflowing: ${Math.round(overflow)}px`;

            scroller.scrollTo({ top: 0, behavior: 'auto' });
            await new Promise((r) => setTimeout(r, 100));

            document.querySelector('[data-testid="chat-post-generation-format-x_post"]').click();
            await new Promise((r) => setTimeout(r, 400));
            document.querySelector('[data-testid="chat-post-generation-style-image_card"]').click();

            for (let i = 0; i < 60; i++) {
                await new Promise((r) => setTimeout(r, 50));

                if (scroller.scrollTop < 1) continue;

                if (scroller.scrollHeight - scroller.clientHeight - scroller.scrollTop <= 4) return true;
            }

            return `stuck: ${Math.round(scroller.scrollHeight - scroller.clientHeight - scroller.scrollTop)}px from bottom, scrollTop ${Math.round(scroller.scrollTop)}`;
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
