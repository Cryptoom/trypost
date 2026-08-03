<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutPurchaseTracking extends Model
{
    use HasUuids;

    protected $fillable = [
        'account_id',
        'session_id',
        'kind',
        'payload',
        'verified_at',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'verified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
