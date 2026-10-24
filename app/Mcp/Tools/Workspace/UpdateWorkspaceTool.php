<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Workspace;

use App\Enums\Workspace\BrandFont;
use App\Enums\Workspace\BrandVoiceTrait;
use App\Enums\Workspace\ContentLanguage;
use App\Enums\Workspace\ImageStyle;
use App\Enums\Workspace\StoryMotionEffect;
use App\Enums\Workspace\StoryMotionFont;
use App\Enums\Workspace\StoryMotionMode;
use App\Http\Resources\Api\WorkspaceResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Services\Media\StoryMotionSettings;
use App\Support\StoryMotionSettingsRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update the current workspace brand settings (name, website, description, voice traits, colors, font, image style, content language) and its Story video settings (story_motion_settings: enabled, instagram, font, effects, mode, logo_layer, ai_notice, ending). All fields are optional, only the fields you pass are changed; inside story_motion_settings only the keys you pass change. The result includes the current story_motion_settings.')]
class UpdateWorkspaceTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'update',
            'Not authorized to update this workspace.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $hex = ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'];

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'brand_website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'brand_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'brand_voice_traits' => ['sometimes', 'nullable', 'array'],
            'brand_voice_traits.*' => ['string', Rule::enum(BrandVoiceTrait::class)],
            'brand_color' => ['sometimes', ...$hex],
            'background_color' => ['sometimes', ...$hex],
            'text_color' => ['sometimes', ...$hex],
            'brand_font' => ['sometimes', 'required', 'string', Rule::in(BrandFont::values())],
            'image_style' => ['sometimes', 'required', 'string', Rule::in(ImageStyle::values())],
            'content_language' => ['sometimes', 'string', Rule::in(ContentLanguage::values())],
            // PATCH:story-motion-settings: merged key by key, same rules as the web endpoint.
            'story_motion_settings' => ['sometimes', 'array'],
            ...StoryMotionSettingsRules::rules('story_motion_settings.'),
        ]);

        $storyMotionChanges = $validated['story_motion_settings'] ?? null;
        unset($validated['story_motion_settings']);

        if (array_key_exists('brand_voice_traits', $validated) && $validated['brand_voice_traits'] !== null) {
            $validated['brand_voice_traits'] = BrandVoiceTrait::coerce($validated['brand_voice_traits']);
        }

        // PATCH semantics: only the fillable fields the caller actually passed
        // are written. Mirrors UpdateWorkspaceRequest's field set minus
        // logo_url (logo upload has its own dedicated flow, not brand settings).
        $payload = array_intersect_key($validated, array_flip($workspace->getFillable()));

        if ($storyMotionChanges !== null) {
            $settings = StoryMotionSettings::forWorkspace($workspace)->with($storyMotionChanges);
            StoryMotionSettingsRules::assertComplete($settings->toArray(), 'story_motion_settings.');
            $payload['story_motion_settings'] = $settings->toArray();
        }

        if ($payload !== []) {
            $workspace->update($payload);
        }

        return Response::structured([
            ...(new WorkspaceResource($workspace))->resolve(),
            'story_motion_settings' => StoryMotionSettings::forWorkspace($workspace)->toArray(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Workspace display name.'),
            'brand_website' => $schema->string()->description('Brand website URL.'),
            'brand_description' => $schema->string()->description('Brand description, up to 2000 characters.'),
            'brand_voice_traits' => $schema->array()
                ->items($schema->string()->enum(BrandVoiceTrait::values()))
                ->description('Brand voice traits (replaces the existing set). See the grouped trait list for single-select spectrum groups vs additive style traits.'),
            'brand_color' => $schema->string()->description('Hex color code (e.g. #FF5733).'),
            'background_color' => $schema->string()->description('Hex color code (e.g. #FFFFFF).'),
            'text_color' => $schema->string()->description('Hex color code (e.g. #111111).'),
            'brand_font' => $schema->string()->enum(BrandFont::values())->description('Brand font family name.'),
            'image_style' => $schema->string()->enum(ImageStyle::values())->description('AI image generation style.'),
            'content_language' => $schema->string()->enum(ContentLanguage::values())->description('Content language code (e.g. "en", "de").'),
            'story_motion_settings' => $schema->object([
                'enabled' => $schema->boolean()->description('Turn Story videos (a moving video instead of a still photo for Facebook photo Stories) on for this workspace. Default off.'),
                'instagram' => $schema->boolean()->description('Also publish photo Stories on Instagram as the same Story video (needs enabled). Default off, Instagram keeps the photo Story.'),
                'font' => $schema->string()->enum(StoryMotionFont::values())->description('Font of the Story video texts.'),
                'effects' => $schema->array()->items($schema->string()->enum(StoryMotionEffect::values()))->description('One or more image effects. With mode "fixed" the first one in the order drift, focus, snap, sparkle is used.'),
                'mode' => $schema->string()->enum(StoryMotionMode::values())->description('How one effect is picked per Story: fixed, rotate or random.'),
                'logo_layer' => $schema->boolean()->description('Show the workspace logo on the Story video (needs an uploaded JPEG or PNG logo up to 2000 x 2000 px and 2 MB).'),
                'ai_notice' => $schema->object([
                    'enabled' => $schema->boolean(),
                    'text' => $schema->string()->description('Notice text, up to 40 characters.'),
                ])->description('AI notice layer, shown vertically at the left edge.'),
                'ending' => $schema->object([
                    'enabled' => $schema->boolean(),
                    'name' => $schema->string()->description('Name, up to 40 characters. No address or phone number.'),
                    'line' => $schema->string()->description('Line, up to 60 characters. No address or phone number.'),
                ])->description('Soft ending in the last 1.5 seconds.'),
            ])->description('Story video settings. Only the keys you pass are changed.'),
        ];
    }
}
