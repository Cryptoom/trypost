<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Ai\Templates\AiTemplateRegistry;
use App\Ai\Templates\Concerns\ResolvesContentType;
use App\Ai\Tools\WorkspaceWriteTool;
use App\Enums\Ai\ContentStyle;
use App\Events\Ai\PostCreationReady;
use App\Jobs\Ai\StreamPostCreation;
use App\Services\Ai\PostGenerationCatalog;
use App\Support\AiPromptRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Starts an AI post generation and returns immediately.
 *
 * The tool never waits for the generation: it validates the choices the model
 * collected, dispatches {@see StreamPostCreation} on the `ai` queue, and
 * answers with the creation id plus the private channel
 * {@see PostCreationReady} broadcasts the finished post on. That is what keeps
 * a chat turn cheap — no PHP worker is held for the length of a generation.
 *
 * Every argument comes from a language model, so each one is validated here
 * and every failure names both what was wrong and the valid options, so the
 * model can correct itself and retry instead of reporting a dead end.
 *
 * It replaces App\Http\Controllers\App\PostAiCreateController::start() and
 * carries that endpoint's rules (App\Http\Requests\App\Ai\StartPostCreationRequest)
 * over to chat.
 */
class GeneratePostTool extends WorkspaceWriteTool
{
    use ResolvesContentType;

    /**
     * Upper bound on generated images, inherited from StartPostCreationRequest.
     * A format may allow fewer — see ContentType::maxMediaCount().
     */
    private const MAX_IMAGE_COUNT = 10;

    public function name(): string
    {
        return 'generate_post';
    }

    public function description(): Stringable|string
    {
        return 'Generate a post with AI in the current workspace, using the format, style and prompt the user chose. Call start_post_generation first to learn which formats and styles this workspace supports, and confirm those choices with the user before calling this. Generation runs in the background: this tool returns as soon as it starts, with a creation id and the channel the finished post is announced on, so never claim the post is ready — tell the user it is being generated.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->required()->description('What the post should be about, in the user\'s own words. Between '.AiPromptRules::PROMPT_MIN_LENGTH.' and '.AiPromptRules::PROMPT_MAX_LENGTH.' characters.'),
            'format' => $schema->string()->required()->description('The format to generate for, taken from the formats start_post_generation returned for this workspace, e.g. "x_post" or "instagram_carousel".'),
            'style' => $schema->string()->required()->enum(app(AiTemplateRegistry::class)->keys())->description('The visual style key, taken from the styles start_post_generation returned.'),
            'social_account_id' => $schema->string()->description('The id of the connected account the post is for, taken from the chosen format\'s accounts. Required for a style whose needs_account is true.'),
            'image_count' => $schema->integer()->min(0)->max(self::MAX_IMAGE_COUNT)->description('How many images to generate. 0 for a text-only post. Defaults to 0.'),
            'date' => $schema->string()->description('Optional date the post is meant for, as Y-m-d.'),
            'apply_brand_visuals' => $schema->boolean()->description('Whether the generated images use the workspace brand palette. Defaults to true.'),
        ];
    }

    protected function run(Request $request): string
    {
        $format = $request->string('format')->trim()->value();
        $style = $request->string('style')->trim()->value();
        $socialAccountId = $request->filled('social_account_id')
            ? $request->string('social_account_id')->trim()->value()
            : null;

        $error = $this->aiAccessError()
            ?? $this->argumentError($request)
            ?? $this->formatError($format)
            ?? $this->styleError($style, $socialAccountId)
            ?? $this->socialAccountError($socialAccountId)
            ?? $this->imageCountError($format, $request->integer('image_count'));

        if ($error !== null) {
            return $this->error($error);
        }

        $creationId = Str::uuid()->toString();

        StreamPostCreation::dispatch(
            userId: $this->user->id,
            creationId: $creationId,
            workspaceId: $this->workspace->id,
            format: $format,
            socialAccountId: $socialAccountId,
            imageCount: $request->integer('image_count'),
            prompt: $request->string('prompt')->trim()->value(),
            date: $request->filled('date') ? $request->string('date')->trim()->value() : null,
            template: $style,
            applyBrandVisuals: $request->boolean('apply_brand_visuals', true),
        );

        return $this->json([
            'data' => [
                'creation_id' => $creationId,
                'channel' => $this->channelFor($creationId),
            ],
        ]);
    }

    /**
     * The same `useAi` gate the replaced controller ran before dispatching.
     * Usage itself is recorded by StreamPostCreation, so nothing is metered
     * here — doing it in both places would bill the account twice.
     */
    private function aiAccessError(): ?string
    {
        $gate = Gate::forUser($this->user)->inspect('useAi', $this->workspace->account);

        return $gate->denied() ? (string) $gate->message() : null;
    }

    /**
     * Shape rules carried over from StartPostCreationRequest, including the
     * shared prompt bounds. The framework's own messages already name the
     * offending argument and the bound it broke.
     */
    private function argumentError(Request $request): ?string
    {
        $validator = Validator::make($request->toArray(), [
            'prompt' => AiPromptRules::wizardPromptRule(),
            'social_account_id' => ['nullable', 'uuid'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_IMAGE_COUNT],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ], attributes: [
            'social_account_id' => 'social_account_id',
            'image_count' => 'image_count',
        ]);

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    /**
     * The format must be one the catalog offers for THIS workspace, not merely
     * a format the platform knows: a format whose platform has no connected
     * account cannot produce a publishable post.
     */
    private function formatError(string $format): ?string
    {
        $available = array_values(array_unique(
            array_column(data_get(PostGenerationCatalog::forWorkspace($this->workspace), 'formats', []), 'value')
        ));

        if ($available === []) {
            return 'This workspace has no connected social accounts, so there is no format to generate a post for. Ask the user to connect an account first.';
        }

        if (in_array($format, $available, true)) {
            return null;
        }

        $options = implode(', ', $available);

        if ($format === '') {
            return "The \"format\" argument is required. Call start_post_generation and pass one of: {$options}.";
        }

        return "The format \"{$format}\" isn't available in this workspace. Call start_post_generation and pass one of: {$options}.";
    }

    /**
     * The style key must exist in the registry — validated here rather than by
     * catching AiTemplateRegistry::find()'s InvalidArgumentException, which
     * WorkspaceTool::handle() would flatten into the generic error the model
     * cannot act on.
     */
    private function styleError(string $style, ?string $socialAccountId): ?string
    {
        $keys = app(AiTemplateRegistry::class)->keys();
        $options = implode(', ', $keys);

        if ($style === '') {
            return "The \"style\" argument is required. Valid styles are: {$options}.";
        }

        if (! in_array($style, $keys, true)) {
            return "The style \"{$style}\" doesn't exist. Valid styles are: {$options}.";
        }

        $needsAccount = ContentStyle::tryFrom($style)?->needsAccount() ?? false;

        if ($needsAccount && blank($socialAccountId)) {
            return "The \"{$style}\" style renders the post as the account's own card, so it needs a connected account. Pass social_account_id using one of the account ids start_post_generation returned for this format.";
        }

        return null;
    }

    private function socialAccountError(?string $socialAccountId): ?string
    {
        if ($socialAccountId === null) {
            return null;
        }

        $owned = $this->workspace->socialAccounts()->whereKey($socialAccountId)->exists();

        return $owned
            ? null
            : "The social account \"{$socialAccountId}\" doesn't belong to this workspace. Use one of the account ids start_post_generation returned.";
    }

    /**
     * Beyond the global 0-10 bound, a format never accepts more media than the
     * platform itself does, so a generation that asked for more could never be
     * published.
     */
    private function imageCountError(string $format, int $imageCount): ?string
    {
        $max = self::resolveContentType($format)?->maxMediaCount();

        if ($max === null || $imageCount <= $max) {
            return null;
        }

        return "The format \"{$format}\" accepts at most {$max} images. Call generate_post again with image_count set to {$max} or fewer.";
    }

    /**
     * The private channel the finished post is announced on, taken from the
     * event itself so the name is never spelled out a second time. Echo
     * subscribes with `private()`, which adds the prefix back.
     */
    private function channelFor(string $creationId): string
    {
        $channel = (new PostCreationReady($this->user->id, $creationId))->broadcastOn();

        return Str::after($channel->name, 'private-');
    }
}
