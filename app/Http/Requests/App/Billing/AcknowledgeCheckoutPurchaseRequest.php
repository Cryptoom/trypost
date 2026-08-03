<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Billing;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeCheckoutPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAccountOwner() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'max:255'],
        ];
    }
}
