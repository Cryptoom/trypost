<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Workspace;

use App\Enums\Workspace\BrandFont;
use App\Enums\Workspace\BrandVoiceTrait;
use App\Enums\Workspace\ContentLanguage;
use App\Enums\Workspace\ImageStyle;
use App\Http\Resources\Api\WorkspaceResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update the current workspace brand settings (name, website, description, voice traits, colors, font, image style, content language). All fields are optional, only the fields you pass are changed.')]
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
        ]);

        // PATCH semantics: only the fillable fields the caller actually passed
        // are written. Mirrors UpdateWorkspaceRequest's field set minus
        // logo_url (logo upload has its own dedicated flow, not brand settings).
        $payload = array_intersect_key($validated, array_flip($workspace->getFillable()));

        if ($payload !== []) {
            $workspace->update($payload);
        }

        return Response::structured((new WorkspaceResource($workspace))->resolve());
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
        ];
    }
}
