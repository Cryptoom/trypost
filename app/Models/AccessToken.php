<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AccessTokenObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Token;

#[ObservedBy(AccessTokenObserver::class)]
class AccessToken extends Token
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'user_id',
        'client_id',
        'workspace_id',
        'name',
        'scopes',
        'revoked',
        'expires_at',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'json',
            'revoked' => 'bool',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Active OAuth grants used by MCP clients (excludes personal access API keys).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveMcpOAuth(Builder $query): Builder
    {
        return $query
            ->mcpOAuth()
            ->where('revoked', false)
            ->where(function (Builder $expires): void {
                $expires->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMcpOAuth(Builder $query): Builder
    {
        return $query
            ->whereJsonContains('scopes', 'mcp:use')
            ->whereHas(
                'client',
                fn (Builder $client): Builder => $client
                    ->where('revoked', false)
                    ->whereJsonDoesntContain('grant_types', 'personal_access'),
            );
    }

    /**
     * Whether this token belongs to an MCP OAuth client (not a personal access
     * API key) and is unexpired. Intentionally ignores `revoked`: the observer
     * needs a stable answer while a revoke is mid-flight so it can broadcast
     * the disconnect. Use the `activeMcpOAuth` scope for "currently usable".
     */
    public function isMcpOAuthGrant(): bool
    {
        $this->loadMissing('client');

        if ($this->client === null || $this->client->revoked || $this->client->hasGrantType('personal_access')) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function isUsableMcpGrant(
        ?User $user = null,
        ?Workspace $workspace = null,
        bool $ignoreRevocation = false,
    ): bool
    {
        if (
            ! $this->isMcpOAuthGrant()
            || (! $ignoreRevocation && $this->revoked)
            || ! in_array('mcp:use', $this->scopes ?? [], true)
        ) {
            return false;
        }

        $user ??= User::query()
            ->with('currentWorkspace')
            ->find($this->user_id);

        if (! $user instanceof User) {
            return false;
        }

        $workspace ??= $this->workspace ?? $user->currentWorkspace;

        return $workspace instanceof Workspace
            && $user->can('createPost', $workspace);
    }
}
