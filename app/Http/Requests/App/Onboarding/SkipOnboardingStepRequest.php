<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Onboarding;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SkipOnboardingStepRequest extends FormRequest
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
            'step' => ['required', 'string', Rule::in(ResolveOnboardingStatus::SKIPPABLE_STEPS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'step' => $this->route('step'),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        // Unknown / required steps stay 404 (not 422) — matches the route allowlist contract.
        if ($validator->errors()->has('step')) {
            throw new NotFoundHttpException;
        }

        parent::failedValidation($validator);
    }
}
