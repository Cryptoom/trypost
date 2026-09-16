<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Support\PostMediaRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d'],
            ...PostMediaRules::rules(hosted: true),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            PostMediaRules::assertHostedMediaExists(
                $validator,
                $this->user()->currentWorkspace,
                (array) $this->input('media', []),
            );
        });
    }
}
