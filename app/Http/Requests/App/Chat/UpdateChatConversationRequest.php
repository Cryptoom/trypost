<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Chat;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChatConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:250'],
        ];
    }
}
