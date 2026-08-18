<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceConversation\Status;
use Database\Factories\WorkspaceConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkspaceConversation extends Model
{
    /** @use HasFactory<WorkspaceConversationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'workspace_id',
        'user_id',
        'title',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WorkspaceConversationMessage::class)->oldest();
    }

    /**
     * Conversations shown in the sidebar: this user's, in this workspace, titled.
     */
    public function scopeListable(Builder $query, string $workspaceId, string $userId): Builder
    {
        return $query->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->whereNotNull('title')
            ->latest('updated_at');
    }

    public function isIdle(): bool
    {
        return $this->status === Status::Idle;
    }
}
