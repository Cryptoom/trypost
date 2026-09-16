<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Support\PostMediaRules;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $status = $this->input('status');

        $enforcesMediaCompatibility = in_array(
            $status,
            [Status::Scheduled->value, Status::Publishing->value],
            true,
        );

        return [
            'status' => ['required', 'string', Rule::in([Status::Draft->value, Status::Scheduled->value, Status::Publishing->value])],
            'content' => [
                'nullable',
                'string',
                'max:10000',
                Rule::when(
                    $enforcesMediaCompatibility,
                    [new ContentFitsPlatformLimits($this->resolveSelectedPlatforms())]
                ),
            ],
            ...PostMediaRules::rules(hosted: true),
            'scheduled_at' => PostStatusRules::scheduledAtRules($this->route('post'), $status),
            'platforms' => ['sometimes', 'array'],
            'platforms.*.id' => ['required', 'uuid', Rule::exists('post_platforms', 'id')->where('post_id', $this->route('post')->id)],
            'platforms.*.content_type' => [
                $enforcesMediaCompatibility ? 'required' : 'sometimes',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
            ],
            // Per-platform media assignment (Edit.vue's media toggle grid). An
            // id must be one of the media items this same request is saving on
            // the post, never an arbitrary/unrelated media id (IDOR guard).
            'platforms.*.media_ids' => ['sometimes', 'array'],
            'platforms.*.media_ids.*' => ['string', Rule::in($this->postMediaIds())],
            ...PostPlatformMetaRules::rules(),
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $this->user()->currentWorkspace->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PostPlatformMetaRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return PostPlatformMetaRules::attributes();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            PostMediaRules::assertHostedMediaExists(
                $validator,
                $this->user()->currentWorkspace,
                (array) $this->input('media', []),
            );
        });

        $validator->after(function (Validator $validator): void {
            if (! $this->isPublishingOrScheduling()) {
                return;
            }

            $this->addMediaCompatibilityErrors($validator);
        });

        $validator->after(function (Validator $validator): void {
            if (! $this->isPublishingOrScheduling()) {
                return;
            }

            $platforms = $this->input('platforms', []);
            $ids = collect($platforms)->pluck('id')->filter()->all();

            $platformsById = $this->route('post')
                ->postPlatforms()
                ->whereIn('id', $ids)
                ->pluck('platform', 'id');

            PostPlatformMetaRules::addRequiredOnPublishErrors(
                $validator,
                $platforms,
                fn ($platform) => $platformsById[data_get($platform, 'id')] ?? null,
            );
        });
    }

    private function isPublishingOrScheduling(): bool
    {
        return in_array(
            $this->input('status'),
            [Status::Scheduled->value, Status::Publishing->value],
            true,
        );
    }

    /**
     * Validate every platform's *effective* content_type (resubmitted in this
     * request, or its stored value) against its own *effective* media: the
     * request's media when resubmitted (applied to every platform, there's no
     * per-platform media field in the request today), otherwise that
     * platform's own scoped media (PostPlatform::scopedMediaItems()), falling
     * back further to the post's full stored media. Mirrors the public API's
     * withValidator check (App\Http\Requests\Api\Post\UpdatePostRequest).
     */
    private function addMediaCompatibilityErrors(Validator $validator): void
    {
        /** @var Post $post */
        $post = $this->route('post');

        $entries = ContentTypeCompatibleWithMedia::entriesForUpdate(
            $post,
            $this->has('platforms') ? (array) $this->input('platforms', []) : null,
            $this->has('media') ? (array) $this->input('media', []) : null,
        );

        foreach (ContentTypeCompatibleWithMedia::errorsFor($entries) as $key => $message) {
            $validator->errors()->add($key, $message);
        }
    }

    /**
     * The media ids this request is saving on the post itself, the only ids
     * a `platforms.*.media_ids` entry is allowed to reference.
     *
     * @return array<int, string>
     */
    private function postMediaIds(): array
    {
        return collect($this->input('media', []))->pluck('id')->filter()->all();
    }

    /**
     * @return Collection<int|string, Platform>
     */
    private function resolveSelectedPlatforms(): Collection
    {
        $ids = collect($this->input('platforms', []))->pluck('id')->filter()->all();
        if (empty($ids)) {
            return collect();
        }

        return $this->route('post')
            ->postPlatforms()
            ->whereIn('id', $ids)
            ->pluck('platform', 'id');
    }
}
