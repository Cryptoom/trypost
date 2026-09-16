<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot for `PostPlatform::media()`: which of a post's media items apply to a
 * given platform. A dedicated `id` primary key (via HasUuids) instead of the
 * plain composite-key pivot used elsewhere (see `post_workspace_label`) is
 * deliberate: it makes an individual row directly addressable for debugging
 * (single id in a log line, `firstOrFail()` by id) instead of needing both
 * foreign keys every time. The `(media_id, post_platform_id)` uniqueness is
 * still enforced separately by the migration's own unique index.
 *
 * HasUuids generates the id in the model's `creating` event, which only fires
 * for a custom pivot class (`belongsToMany(...)->using(self::class)`). The
 * plain `attach()` path used without `using()` bypasses model events entirely
 * and would leave `id` null.
 */
class MediaPostPlatform extends Pivot
{
    use HasUuids;

    protected $table = 'media_post_platform';
}
