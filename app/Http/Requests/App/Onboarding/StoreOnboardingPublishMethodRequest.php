<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\User\PublishMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWelcomePublishMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'publish_method' => ['required', Rule::enum(PublishMethod::class)],
        ];
    }
}
