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
            ->where('revoked', false)
            ->whereHas(
                'client',
                fn (Builder $client): Builder => $client->whereJsonDoesntContain('grant_types', 'personal_access'),
            );
    }

    public function isMcpOAuthClient(): bool
    {
        $this->loadMissing('client');

        return $this->client !== null && ! $this->client->hasGrantType('personal_access');
    }
}
