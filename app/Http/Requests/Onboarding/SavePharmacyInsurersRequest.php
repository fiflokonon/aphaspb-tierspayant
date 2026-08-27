<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SavePharmacyInsurersRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'insurers' => ['nullable', 'array'],
            'insurers.*' => ['integer', 'exists:insurers,id'],
            'other' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Declaring without a single insurer would leave nothing to declare, so at
     * least one of the two inputs has to carry something.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $picked = $this->input('insurers', []);
            $other = trim((string) $this->input('other', ''));

            if ($picked === [] && $other === '') {
                $validator->errors()->add('insurers', 'Choisissez au moins un assureur.');
            }
        });
    }
}
