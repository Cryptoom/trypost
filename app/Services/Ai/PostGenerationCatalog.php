<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Ai\Templates\AiContentTemplate;
use App\Ai\Templates\AiTemplateRegistry;
use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Builds the AI post-generation catalog (formats, styles, and brand-visuals
 * default) offered to a workspace in chat.
 *
 * The format list is `ContentType::aiSupported()` plus the Instagram-carousel
 * pseudo-format — the backend's own definition of a valid AI generation
 * format, already enforced by `StartPostCreationRequest::rules()`. Sourcing
 * it from there instead of hand-listing formats means the catalog can never
 * drift from what the generation pipeline actually accepts, and a new
 * platform never has to be added twice.
 */
final class PostGenerationCatalog
{
    /**
     * @return array{
     *     formats: list<array{value: string, platform: string, accounts: list<array{id: string, label: string}>}>,
     *     styles: list<array{key: string, name: string, description: string, preview: string, supported_formats: list<string>, applies_brand_visuals: bool}>,
     *     applies_brand_visuals_default: bool,
     * }
     */
    public static function forWorkspace(Workspace $workspace): array
    {
        $accountsByPlatform = $workspace->socialAccounts()->active()->get()
            ->groupBy(fn (SocialAccount $account): string => $account->platform->value);

        return [
            'formats' => self::buildFormats($accountsByPlatform),
            'styles' => self::buildStyles(),
            'applies_brand_visuals_default' => true,
        ];
    }

    /**
     * Every format value the catalog can offer, regardless of which
     * platforms a workspace has connected. This is the single source of
     * truth for validating a submitted format (`generate_post`, Task 4) once
     * `StartPostCreationRequest` — the current holder of this same list — is
     * retired.
     *
     * @return list<string>
     */
    public static function allowedFormats(): array
    {
        return array_column(self::formatCatalog(), 'value');
    }

    /**
     * @param  Collection<string, Collection<int, SocialAccount>>  $accountsByPlatform
     * @return list<array{value: string, platform: string, accounts: list<array{id: string, label: string}>}>
     */
    private static function buildFormats(Collection $accountsByPlatform): array
    {
        $formats = [];

        foreach (self::formatCatalog() as $entry) {
            $type = data_get($entry, 'type');

            foreach ($type->compatiblePlatforms() as $platform) {
                $accounts = $accountsByPlatform->get($platform->value, collect());

                if ($accounts->isEmpty()) {
                    continue;
                }

                $formats[] = [
                    'value' => data_get($entry, 'value'),
                    'platform' => $platform->value,
                    'accounts' => $accounts->map(fn (SocialAccount $account): array => [
                        'id' => $account->id,
                        'label' => $account->display_label,
                    ])->all(),
                ];
            }
        }

        return $formats;
    }

    /**
     * `ContentType::aiSupported()` — the same allow-list
     * `StartPostCreationRequest` validates a submitted format against —
     * paired with the `ContentType` case used to resolve compatible
     * platforms, plus the Instagram-carousel pseudo-format appended the same
     * way that request appends it. `CAROUSEL_FORMAT` is not itself a
     * `ContentType` case — a carousel post is persisted as `InstagramFeed` —
     * so it resolves platforms through `InstagramFeed` too.
     *
     * @return list<array{value: string, type: ContentType}>
     */
    private static function formatCatalog(): array
    {
        $entries = array_map(fn (ContentType $type): array => [
            'value' => $type->value,
            'type' => $type,
        ], ContentType::aiSupported());

        $entries[] = ['value' => ContentType::CAROUSEL_FORMAT, 'type' => ContentType::InstagramFeed];

        return $entries;
    }

    /**
     * Same shape `PostController::create()` builds from the AI template
     * registry, reused here rather than called there — that controller
     * method is being deleted once the chat replaces the dedicated screen.
     *
     * @return list<array{key: string, name: string, description: string, preview: string, supported_formats: list<string>, applies_brand_visuals: bool}>
     */
    private static function buildStyles(): array
    {
        return array_map(fn (AiContentTemplate $template): array => [
            'key' => $template->key(),
            'name' => trans($template->name()),
            'description' => trans($template->description()),
            'preview' => $template->previewAsset(),
            'supported_formats' => $template->supportedFormats(),
            'applies_brand_visuals' => $template->appliesBrandVisuals(),
        ], app(AiTemplateRegistry::class)->all());
    }
}
