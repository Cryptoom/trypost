<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Services\Ai\PostGenerationCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the {@see PostGenerationCatalog} array for the
 * `start_post_generation` chat tool response. The resource passes the
 * catalog through as-is (it is already the exact shape the frontend's
 * progressive choice card consumes) rather than a model transform — this
 * exists so the tool never hands raw array output to the model unwrapped.
 */
class PostGenerationCatalogResource extends JsonResource
{
    /**
     * @return array{
     *     formats: list<array{value: string, platform: string, accounts: list<array{id: string, label: string}>}>,
     *     styles: list<array{key: string, name: string, description: string, preview: string, supported_formats: list<string>, applies_brand_visuals: bool}>,
     *     applies_brand_visuals_default: bool,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'formats' => data_get($this->resource, 'formats', []),
            'styles' => data_get($this->resource, 'styles', []),
            'applies_brand_visuals_default' => data_get($this->resource, 'applies_brand_visuals_default', true),
        ];
    }
}
