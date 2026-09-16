<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot for `PostPlatform::media()`: which of a post's media items apply to a
 * given platform. A dedicated `id` primary key (via HasUuids) instead of the
 * plain composite-key pivot used elsewhere (see `post_workspace_label`),
 * because the same media item can be attached to more than one platform of
 * the same post, so there is no natural single unique key here, and an
 * explicit id makes a row directly addressable when debugging.
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
