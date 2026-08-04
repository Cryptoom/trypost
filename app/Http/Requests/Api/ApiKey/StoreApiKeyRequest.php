<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ApiKey;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workspace = $user?->currentWorkspace;

        return $workspace !== null
            && $user->can('manageTeam', $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => [
                'nullable',
                'date',
                'after:today',
                'before_or_equal:'.now()
                    ->addDays(max(1, (int) config('trypost.api_keys.expiration_days')))
                    ->toDateString(),
            ],
        ];
    }
}
