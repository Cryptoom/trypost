<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Ai\Tools\WorkspaceTool;
use App\Services\Ai\PostGenerationCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The catalog behind the post-generation card, plus the topic the card
 * pre-fills its prompt field with.
 *
 * The topic is an argument rather than something the card infers because only
 * the model has read the conversation. It restates the user's own words, which
 * would otherwise risk a paraphrase passing for what they asked for — except
 * the card puts the text in front of them to confirm or edit before a single
 * token is generated, so a bad restatement is visible rather than silent. The
 * user's confirmed text is what reaches generate_post's `prompt`.
 */
class StartPostGenerationTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'start_post_generation';
    }

    public function description(): Stringable|string
    {
        return 'Call this when the user asks to create or generate a post, before generating anything. Returns the formats (platforms and connected accounts) and styles this workspace can generate a post in, plus the topic you pass back. This tool generates nothing and changes nothing; use generate_post once the user has picked. The interface renders the result as an interactive card the user clicks through, so after calling this, do NOT list the formats or styles, describe them, or ask which one the user wants — the card already asks, and repeating it in text gives the user two conflicting prompts. Say at most one short sentence and stop. The user answers by clicking, which arrives as their next message naming the choices they made.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->description('What the user said the post should be about, in their own words, taken from the conversation — e.g. "the X launch". The card shows it in a field the user confirms or edits before anything is generated, so it is a starting point, not a decision. Leave this out when the user has not said what the post is about: an empty field asks them, an invented topic gets confirmed by someone skimming.'),
        ];
    }

    protected function run(Request $request): string
    {
        $catalog = PostGenerationCatalog::forWorkspace($this->workspace);
        $catalog['topic'] = $request->filled('topic') ? $request->string('topic')->trim()->value() : '';

        return $this->json(['data' => $catalog]);
    }
}
