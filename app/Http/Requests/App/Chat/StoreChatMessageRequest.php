<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A chat turn carries exactly one of two payloads: a new user message, or the
 * approval decisions that resume a run paused on a tool call. Sending both is
 * meaningless — the SDK would drop one — so they are mutually exclusive.
 */
class StoreChatMessageRequest extends FormRequest
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
            'message' => ['nullable', 'string', 'max:10000', 'required_without:decisions', 'prohibits:decisions'],
            'decisions' => ['nullable', 'array', 'required_without:message'],
            'decisions.*' => ['array'],
            'decisions.*.action' => ['required', Rule::in(['approve', 'reject'])],
            'decisions.*.result' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
