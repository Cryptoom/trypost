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
        return 'Call this when the user asks to create or generate a post, before generating anything. Returns the formats (platforms and connected accounts) and styles this workspace can generate a post in. This tool generates nothing and changes nothing; use generate_post once the user has picked. The interface renders the result as an interactive card the user clicks through, so after calling this, do NOT list the formats or styles, describe them, or ask which one the user wants — the card already asks, and repeating it in text gives the user two conflicting prompts. Say at most one short sentence and stop. The user answers by clicking, which arrives as their next message naming the choices they made.';
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
