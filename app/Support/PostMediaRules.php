<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Media\Source;
use App\Models\Media;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Single source of truth for inline post `media` validation, shared by the post
 * create/update flows. The web sends already-hosted media (id + path) and tracks
 * its source; the public REST API may send a bare external `url` we download and
 * host, so the id/path/url rules differ by contract.
 */
class PostMediaRules
{
    /**
     * Maximum stored length (characters) for a media item's alt text. Publishers
     * truncate further to each platform's own cap via Platform::altTextMaxLength().
     */
    public const ALT_TEXT_MAX_LENGTH = 2000;

    /**
     * Workspace media collections a post may reference by id: uploaded/library
     * assets, and slides or images the AI pipeline generated for this workspace
     * (PostImagePipeline, RegeneratePostMediaImage). Other collections such as
     * `logo` or `avatar` are not post media and stay rejected.
     *
     * @var array<int, string>
     */
    public const POST_MEDIA_COLLECTIONS = ['assets', 'ai-generated'];

    /**
     * @param  bool  $hosted  true (web): items must already be hosted (id + path
     *                        required); false (API): a bare external `url` is
     *                        accepted (and downloaded).
     * @return array<string, mixed>
     */
    public static function rules(bool $hosted): array
    {
        return [
            'media' => ['sometimes', 'array'],
            'media.*.id' => $hosted ? ['required', 'string'] : ['sometimes', 'nullable', 'string'],
            'media.*.path' => $hosted ? ['required', 'string', 'max:500'] : ['sometimes', 'nullable', 'string', 'max:500'],
            'media.*.url' => $hosted
                ? ['required', 'string', 'max:2048']
                : ['required', 'string', 'max:2048', 'url:http,https'],
            'media.*.type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'media.*.mime_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'media.*.original_filename' => ['sometimes', 'nullable', 'string', 'max:500'],
            'media.*.size' => ['sometimes', 'nullable', 'integer'],
            'media.*.meta' => ['sometimes', 'nullable', 'array', static function (string $attribute, mixed $value, Closure $fail): void {
                $altText = data_get($value, 'alt_text');

                if ($altText === null) {
                    return;
                }

                if (! is_string($altText)) {
                    $fail('validation.string')->translate(['attribute' => trans('posts.edit.alt_text.label')]);

                    return;
                }

                if (mb_strlen($altText) > self::ALT_TEXT_MAX_LENGTH) {
                    $fail('validation.max.string')->translate(['attribute' => trans('posts.edit.alt_text.label'), 'max' => self::ALT_TEXT_MAX_LENGTH]);
                }
            }],
            'media.*.source' => ['sometimes', 'nullable', 'string', Rule::in(array_column(Source::cases(), 'value'))],
            'media.*.source_meta' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Reject inline media items that claim to already be hosted (`id` and/or
     * `path` set) but don't resolve to a real `medias` row owned by this
     * workspace. `rules()` above only checks shape (id is a non-empty string).
     * It never confirms the id exists, so a client could otherwise write an
     * arbitrary id/path pair straight into `posts.media`, including another
     * workspace's real asset (IDOR) or a path nothing backs at all. Mirrors
     * the lookup `FindWorkspaceAsset` already uses for `attach-existing-asset`.
     *
     * A bare `url` with no `id`/`path` is left alone: that's the API-only
     * "please download this external URL" case (`PostMediaRules::rules`
     * with `hosted: false`), and `HostInlineMedia`/`MediaAttacher` handle it
     * by fetching the URL and creating a fresh, workspace-owned `Media` row
     * before anything is persisted. There's no pre-existing id to check yet.
     *
     * @param  array<int, array<string, mixed>>  $media
     */
    public static function assertHostedMediaExists(Validator $validator, Workspace $workspace, array $media): void
    {
        foreach ($media as $index => $item) {
            $id = data_get($item, 'id');
            $path = data_get($item, 'path');

            if (blank($id)) {
                if (filled($path)) {
                    $validator->errors()->add(
                        "media.{$index}.id",
                        'The media id field is required when path is present.',
                    );
                }

                // Blank id, blank path: a bare external url for HostInlineMedia to fetch. Nothing to verify yet.
                continue;
            }

            if ($validator->errors()->has("media.{$index}.id")) {
                // A shape rule (e.g. "must be a string") already failed for this item.
                continue;
            }

            // A non-UUID id can never match a real medias row (id is a UUID
            // primary key), and Postgres rejects it as an invalid uuid literal
            // before the query even runs, an unhandled 500 instead of a
            // graceful 422. Fail the same way a real, absent id would.
            if (! Str::isUuid((string) $id)) {
                $validator->errors()->add("media.{$index}.id", 'Media not found.');

                continue;
            }

            $exists = Media::query()
                ->where('mediable_type', Relation::getMorphAlias(Workspace::class))
                ->where('mediable_id', $workspace->id)
                ->whereIn('collection', self::POST_MEDIA_COLLECTIONS)
                ->whereKey($id)
                ->exists();

            if (! $exists) {
                $validator->errors()->add("media.{$index}.id", 'Media not found.');
            }
        }
    }
}
