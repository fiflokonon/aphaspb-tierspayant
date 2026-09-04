<?php

namespace App\Http\Requests\Onboarding;

use App\Models\Pharmacy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePharmacyProfileRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $current = $this->user()?->currentPharmacy;

        return [
            'name' => ['required', 'string', 'max:200'],
            'onpb_license' => [
                'nullable',
                'string',
                'max:50',
                $current === null
                    ? Rule::unique(Pharmacy::class, 'onpb_license')
                    : Rule::unique(Pharmacy::class, 'onpb_license')->ignore($current->id),
            ],
            'city' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => "Le nom de l'officine est obligatoire.",
            'city.required' => 'La ville est obligatoire.',
            'onpb_license.unique' => 'Ce numéro ONPB est déjà enregistré.',
        ];
    }
}
