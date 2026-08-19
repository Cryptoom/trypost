<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Ai\Tools\WorkspaceTool;
use App\Services\Ai\PostGenerationCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class StartPostGenerationTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'start_post_generation';
    }

    public function description(): Stringable|string
    {
        return 'Call this when the user asks to create or generate a post, before generating anything. Returns the formats (platforms and connected accounts) and styles this workspace can generate a post in, so the user can choose one. This tool generates nothing and changes nothing — it only presents choices; use generate_post to actually create the post once the user has picked a format and style.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): string
    {
        return $this->json(['data' => PostGenerationCatalog::forWorkspace($this->workspace)]);
    }
}
