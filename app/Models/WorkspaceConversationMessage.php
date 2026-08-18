<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceConversation\Message\Role;
use Database\Factories\WorkspaceConversationMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceConversationMessage extends Model
{
    /** @use HasFactory<WorkspaceConversationMessageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'workspace_conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_results',
        'usage',
        'meta',
        'approval_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'usage' => 'array',
            'meta' => 'array',
            'approval_state' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WorkspaceConversation::class, 'workspace_conversation_id');
    }
}
