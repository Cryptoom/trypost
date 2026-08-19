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
 * Answer the topic step. It is the one question the card cannot ask with
 * buttons — the post has to be about something — so every path to the submit
 * button now goes through it.
 */
function answerChatTopic(mixed $page, string $topic): void
{
    waitForChatTestId($page, 'chat-post-generation-topic-step');

    $page->fill('@chat-post-generation-topic-input', $topic);

    // Typing is the answer — there is no confirm step, so the final panel
    // appearing is what says the topic was accepted.
    waitForChatTestId($page, 'chat-post-generation-final');
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
 * `$arguments` are merged into the stored tool call, which `ToolReplayer`
 * replays the tool with — that is how the model's `language` and `format`
 * reach the card.
 *
 * @param  array<string, string>  $arguments
 * @return array{0: WorkspaceConversation, 1: SocialAccount}
 */
function chatWithPostGenerationCard(int $priorMessages = 0, string $topic = '', array $arguments = []): array
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
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => array_merge($topic === '' ? [] : ['topic' => $topic], $arguments)]],
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

    $page->click('@chat-post-generation-style-image_card');

    answerChatTopic($page, 'the pricing launch');

    // A single-account format never asks which account to post as.
    waitForChatTestId($page, 'chat-post-generation-images-step');

    $page->assertMissing('@chat-post-generation-account-step')
        ->assertMissing('@chat-post-generation-account-choice')
        ->assertSee(__('chat.post_generation.brand_colors_label'));

    $page->click('@chat-post-generation-submit');

    // The sentence carries the topic: it is what the model reads before it
    // calls generate_post with its `prompt` argument.
    $page->assertSee(__('chat.post_generation.sentence_with_brand', [
        'format' => __('posts.create.steps.format.x_post'),
        'topic' => 'the pricing launch',
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

    answerChatTopic($page, 'the pricing launch');

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
        'topic' => 'the pricing launch',
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

    answerChatTopic($page, 'the pricing launch');

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
            'topic' => 'the pricing launch',
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
                // The topic is the one step with no confirm: its field carries
                // the answer and stays editable, so once it holds a usable
                // topic it is answered content, not an open question.
                const topicInput = el.querySelector('[data-testid="chat-post-generation-topic-input"]');
                if (topicInput) return topicInput.value.trim().length >= 3 ? 'field' : 'question';
                if (el.querySelector('img[src="/images/trypost/icon.png"]')) return 'question';
                return 'other';
            });

            const answered = ['record', 'final', 'field'];

            const answeredAfterQuestion = kinds.some(
                (kind, index) => kind === 'question'
                    && kinds.slice(index + 1).some((later) => answered.includes(later)),
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
    waitForChatTestId($page, 'chat-post-generation-topic-step');

    // Mid-flow: the two answered steps read back as the user's own messages,
    // and the only open question is the last block in the thread.
    expect(chatCardBlocks($page))->toBe('record,record,question|ordered');

    answerChatTopic($page, 'the pricing launch');

    waitForChatTestId($page, 'chat-post-generation-account-step');

    expect(chatCardBlocks($page))->toBe('record,record,field,question|ordered');

    $page->click("@chat-post-generation-account-{$instagramBusiness->id}");
    waitForChatTestId($page, 'chat-post-generation-final');

    // Answered: nothing is left open above the final block, which is where the
    // image count and the brand toggle live — next to the button that acts on
    // them, rather than as a question the conversation walked past.
    expect(chatCardBlocks($page))->toBe('record,record,field,record,final|ordered');

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

    answerChatTopic($page, 'the pricing launch');

    waitForChatTestId($page, 'chat-post-generation-final');

    $page->assertMissing('@chat-post-generation-account-step')
        ->assertMissing('@chat-post-generation-account-choice')
        ->assertVisible('@chat-post-generation-account-auto')
        ->assertMissing('@chat-post-generation-brand-step')
        ->assertSee(__('chat.post_generation.posting_to', ['account' => 'Acme Threads (@acmethreads)']));

    expect(chatCardBlocks($page))->toBe('record,record,field,final|ordered');

    // The account the card picked still reaches the sentence.
    $page->click('@chat-post-generation-submit');

    // tweet_card renders the post as that account's own card and applies no
    // brand visuals, so the sentence carries no brand clause.
    $page->assertSee(__('chat.post_generation.sentence', [
        'format' => __('posts.create.steps.format.threads_post'),
        'topic' => 'the pricing launch',
        'style' => __('posts.ai.templates.tweet_card.name'),
        'images' => __('chat.post_generation.sentence_images_other', ['count' => 2]),
        'account' => 'Acme Threads (@acmethreads)',
    ]));
});

/** The current value of the topic field, which `fill` and `v-model` both drive. */
function chatTopicFieldValue(mixed $page): string
{
    return (string) $page->script(<<<'JS'
        (async () => document.querySelector('[data-testid="chat-post-generation-topic-input"]')?.value ?? 'missing')()
    JS);
}

test('the topic question opens pre-filled with what the model extracted', function () {
    // start_post_generation is replayed with its stored arguments, so the
    // topic the model passed comes back with the catalog.
    [$conversation] = chatWithPostGenerationCard(0, 'o lançamento do X');

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');
    stubChatTurn($page);

    $page->click('@chat-post-generation-format-x_post');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-topic-step');

    expect(chatTopicFieldValue($page))->toBe('o lançamento do X');

    // Left as it arrived, it reaches the sentence — the user saw the topic in
    // an editable field before anything was generated from it.
    waitForChatTestId($page, 'chat-post-generation-submit');

    $page->assertVisible('@chat-post-generation-topic-step');

    $page->click('@chat-post-generation-submit');

    $page->assertSee(__('chat.post_generation.sentence_with_brand', [
        'format' => __('posts.create.steps.format.x_post'),
        'topic' => 'o lançamento do X',
        'style' => __('posts.ai.templates.image_card.name'),
        'images' => __('chat.post_generation.sentence_images_other', ['count' => 2]),
        'account' => 'Acme X (@acmex)',
        'brand' => __('chat.post_generation.sentence_brand_on'),
    ]));
});

test('the topic question opens blank when the model passed no topic', function () {
    [$conversation] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    $page->click('@chat-post-generation-format-x_post');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-topic-step');

    // A blank field asks; an invented topic gets confirmed by someone skimming.
    expect(chatTopicFieldValue($page))->toBe('');
});

test('the card will not confirm a topic the server would reject', function () {
    [$conversation] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    $page->click('@chat-post-generation-format-x_post');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-topic-step');

    // AiPromptRules::PROMPT_MIN_LENGTH is 3, and generate_post refuses less —
    // so the card refuses first: with no confirm button, the final panel is
    // the gate, and it does not exist until the topic is long enough.
    $page->assertMissing('@chat-post-generation-submit')
        ->assertMissing('@chat-post-generation-final');

    $page->fill('@chat-post-generation-topic-input', 'ab');

    $page->assertMissing('@chat-post-generation-submit')
        ->assertMissing('@chat-post-generation-final');

    $page->fill('@chat-post-generation-topic-input', 'abc');

    waitForChatTestId($page, 'chat-post-generation-final');

    $page->assertVisible('@chat-post-generation-submit');
});

test('changing one step after reopening another keeps the reopened question open', function () {
    [$conversation, $instagramBusiness] = chatWithPostGenerationCard();

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    $page->click('@chat-post-generation-format-instagram_feed');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    $page->click('@chat-post-generation-style-image_card');

    answerChatTopic($page, 'the pricing launch');

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

    expect(chatCardBlocks($page))->toBe('record,record,field,question|ordered');
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

test('the card is rendered in the language of the conversation, not the interface', function () {
    // The interface stays English (the app locale in tests); the model
    // reported that the user is writing in Portuguese. Every word of the card
    // has to follow the conversation, or the thread holds two languages.
    [$conversation] = chatWithPostGenerationCard(0, 'o lançamento do X', ['language' => 'pt-BR']);

    expect(app()->getLocale())->toBe('en');

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');
    stubChatTurn($page);

    $page->assertSee(__('chat.post_generation.format_question', [], 'pt-BR'))
        ->assertDontSee(__('chat.post_generation.format_question', [], 'en'))
        ->assertSee(__('posts.create.steps.format.x_post', [], 'pt-BR'))
        ->assertDontSee(__('posts.create.steps.format.x_post', [], 'en'));

    $page->click('@chat-post-generation-format-x_post');
    waitForChatTestId($page, 'chat-post-generation-style-image_card');

    // The record, the Change link, the style catalog and the next question.
    $page->assertSee(__('chat.post_generation.change', [], 'pt-BR'))
        ->assertSee(__('chat.post_generation.style_question', [], 'pt-BR'))
        ->assertDontSee(__('chat.post_generation.style_question', [], 'en'))
        ->assertSee(__('posts.ai.templates.image_card.name', [], 'pt-BR'));

    $page->click('@chat-post-generation-style-image_card');
    waitForChatTestId($page, 'chat-post-generation-topic-step');

    $page->assertSee(__('chat.post_generation.topic_question', [], 'pt-BR'))
        ->assertDontSee(__('chat.post_generation.topic_question', [], 'en'));

    $page->fill('@chat-post-generation-topic-input', 'o lançamento do X');
    waitForChatTestId($page, 'chat-post-generation-submit');

    $page->assertSee(__('chat.post_generation.images_question', [], 'pt-BR'))
        ->assertSee(__('chat.post_generation.brand_colors_label', [], 'pt-BR'))
        ->assertDontSee(__('chat.post_generation.brand_colors_label', [], 'en'));

    $page->click('@chat-post-generation-submit');

    // The sentence is what the model reads before it calls generate_post, and
    // it is the user's own message in the thread — so it, too, is Portuguese.
    $page->assertSee(__('chat.post_generation.sentence_with_brand', [
        'format' => __('posts.create.steps.format.x_post', [], 'pt-BR'),
        'topic' => 'o lançamento do X',
        'style' => __('posts.ai.templates.image_card.name', [], 'pt-BR'),
        'images' => __('chat.post_generation.sentence_images_other', ['count' => 2], 'pt-BR'),
        'account' => 'Acme X (@acmex)',
        'brand' => __('chat.post_generation.sentence_brand_on', [], 'pt-BR'),
    ], 'pt-BR'))->assertSee(__('chat.post_generation.sent', [], 'pt-BR'));
});

test('a format the user already named is recorded rather than asked again', function () {
    // "quero gerar um carrousel de instagram" — the model read it, so the card
    // must not offer the same four Instagram formats back.
    [$conversation] = chatWithPostGenerationCard(0, '', ['format' => 'instagram_carousel']);

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');

    waitForChatTestId($page, 'chat-post-generation-format-choice');

    $page->assertMissing('@chat-post-generation-format-step')
        ->assertVisible('@chat-post-generation-format-choice')
        ->assertSee(__('posts.create.steps.format.instagram_carousel'))
        ->assertMissing('@chat-post-generation-format-instagram_carousel')
        ->assertMissing('@chat-post-generation-format-x_post')
        ->assertDontSee(__('posts.create.steps.format.x_post'));

    // A pick made from a closed list is reversible in one click — which is
    // what makes recording it, rather than asking, safe.
    $page->assertVisible('@chat-post-generation-format-choice-change');

    $page->click('@chat-post-generation-format-choice-change');
    waitForChatTestId($page, 'chat-post-generation-format-step');

    // Reopened, it must stay open: the card applies the model's format once,
    // not again on every re-render of the payload.
    $page->assertMissing('@chat-post-generation-format-choice')
        ->assertVisible('@chat-post-generation-format-instagram_feed');
});

test('a format the workspace cannot post is ignored and the card asks', function () {
    // No LinkedIn account is connected, so the model's format names nothing
    // this workspace can generate. Ignored, never an error.
    [$conversation] = chatWithPostGenerationCard(0, '', ['format' => 'linkedin_post']);

    $page = visit(route('app.chat.show', $conversation));

    waitForChatTestId($page, 'chat-post-generation-card');
    waitForChatTestId($page, 'chat-post-generation-format-step');

    $page->assertVisible('@chat-post-generation-format-step')
        ->assertMissing('@chat-post-generation-format-choice')
        ->assertDontSee(__('posts.create.steps.format.linkedin_post'));
});
