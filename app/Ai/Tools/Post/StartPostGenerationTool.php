<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Ai\Tools\WorkspaceTool;
use App\Enums\Workspace\ContentLanguage;
use App\Services\Ai\PostGenerationCardCopy;
use App\Services\Ai\PostGenerationCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\App;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The catalog behind the post-generation card, in the language of the
 * conversation, plus the two things the model read from the conversation and
 * the card could not: the topic and, when the user named one, the format.
 *
 * The topic is an argument rather than something the card infers because only
 * the model has read the conversation. It restates the user's own words, which
 * would otherwise risk a paraphrase passing for what they asked for — except
 * the card puts the text in front of them to confirm or edit before a single
 * token is generated, so a bad restatement is visible rather than silent. The
 * user's confirmed text is what reaches generate_post's `prompt`.
 *
 * The format is safe on weaker terms: it is picked from a closed list, so the
 * card can render it as an answered record with a Change link — a wrong pick
 * names something the user can read and undo in one click. That is why it is
 * not put back as a pre-selected question the way the topic is: there is
 * nothing to paraphrase.
 *
 * The language decides the locale every string in the payload is resolved in.
 * The assistant answers in whatever language the user writes in, and the
 * interface follows the user's app setting — the two differ often enough that
 * a card translated client-side puts two languages in one thread. Only the
 * model has read the conversation, so only the model can say which one it is.
 */
class StartPostGenerationTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'start_post_generation';
    }

    public function description(): Stringable|string
    {
        return 'Call this when the user asks to create or generate a post, before generating anything — but only once you know what the post should be about. If they have not said, ask them in plain text first and call this with their answer. Returns the formats (platforms and connected accounts) and styles this workspace can generate a post in, plus the topic, format and language you pass back. This tool generates nothing and changes nothing; use generate_post once the user has picked. The interface renders the result as an interactive card the user clicks through. Say one short sentence BEFORE you call this, naming what they are about to choose — a turn keeps text and cards in the order you produced them, so that line lands above the card, where it introduces it. Say nothing after the call: listing the formats or styles, describing them, or asking which one the user wants gives them two prompts competing to be answered, and anything added afterwards lands below the card as stale advice about a choice they have already made. The user answers by clicking, which arrives as their next message naming the choices they made.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->description('What the user said the post should be about, in their own words, taken from the conversation — e.g. "the X launch". Required. When the user has not said what the post is about, do NOT call this tool and do NOT invent a topic: ask them in plain text what the post should be about, and call this once they answer. A post generated about a topic nobody chose wastes the generation and the user\'s credits.'),
            'format' => $schema->string()->enum(PostGenerationCatalog::formatValues())->description('The format the user named, when they named one — "an Instagram carousel" is instagram_carousel, "a tweet" is x_post. The card records it as their choice, with a link to change it, so it never asks again for something they already said. Leave this out when the user did not name a format, or named something outside this list: the card then asks, which is the right outcome. A format this workspace has no connected account for is ignored the same way.'),
            'language' => $schema->string()->enum(ContentLanguage::class)->description('The language the user is writing in, as the code from this list — "quero gerar um carrossel" is pt-BR, "generate a post" is en. Every word of the card is rendered in it, so the card speaks the same language you do. Leave this out when the conversation gives you nothing to go on; the interface language is then used.'),
        ];
    }

    protected function run(Request $request): string
    {
        $locale = $this->resolveLocale($request);
        $topic = $request->filled('topic') ? $request->string('topic')->trim()->value() : '';

        if ($topic === '') {
            return $this->error('No topic was given. Ask the user in plain text what the post should be about, then call start_post_generation again with their answer as the topic. Do not invent one.');
        }

        $catalog = PostGenerationCatalog::forWorkspace($this->workspace, $locale);
        $catalog['locale'] = $locale;
        $catalog['copy'] = PostGenerationCardCopy::forLocale($locale);
        $catalog['topic'] = $topic;
        $catalog['format'] = $this->resolveFormat($request, $catalog);

        return $this->json(['data' => $catalog]);
    }

    /**
     * The conversation's own locale, or the application's when the model named
     * none or named one this app does not ship. Matched case-insensitively
     * because a model that returns "PT-BR" means the same locale we do, and a
     * fallback to English there would be a silent regression rather than a
     * safe default.
     */
    private function resolveLocale(Request $request): string
    {
        $language = $request->filled('language') ? $request->string('language')->trim()->value() : '';

        foreach (ContentLanguage::cases() as $case) {
            if (strcasecmp($case->value, $language) === 0) {
                return $case->value;
            }
        }

        return App::getLocale();
    }

    /**
     * The format the model read from the conversation, kept only when this
     * workspace can actually post it. An unknown or unavailable format is
     * dropped rather than refused: the card then asks, which is exactly what
     * it does when the user named no format at all.
     *
     * @param  array<string, mixed>  $catalog  the payload built by {@see PostGenerationCatalog::forWorkspace()}
     */
    private function resolveFormat(Request $request, array $catalog): ?string
    {
        if (! $request->filled('format')) {
            return null;
        }

        $format = $request->string('format')->trim()->value();

        $available = array_map(
            fn (array $entry): string => data_get($entry, 'value'),
            data_get($catalog, 'formats', []),
        );

        return in_array($format, $available, true) ? $format : null;
    }
}
