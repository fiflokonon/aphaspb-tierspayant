<?php

namespace App\Http\Requests\Onboarding;

use App\Http\Requests\Concerns\ValidatesWhatsappPhone;
use App\Models\City;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class SavePharmacyProfileRequest extends FormRequest
{
    use ValidatesWhatsappPhone;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Exists|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            // Une commune de la liste, pas un texte libre : la région de
            // l'officine se déduit de sa ville.
            'city' => ['required', 'string', Rule::exists(City::class, 'name')],
            'whatsapp_phone' => $this->whatsappPhoneRules(required: true),
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
            'city.exists' => 'Choisissez une ville de la liste.',
            ...$this->whatsappPhoneMessages(),
        ];
    }
}
