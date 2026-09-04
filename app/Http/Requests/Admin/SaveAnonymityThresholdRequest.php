<?php

namespace App\Http\Requests\Admin;

use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveAnonymityThresholdRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'minimum' => [
                'required',
                'integer',
                'min:'.SettingsRepository::ANONYMITY_FLOOR,
                'max:100',
            ],
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
            'minimum.required' => 'Indiquez un nombre d’officines.',
            'minimum.min' => 'Le seuil ne peut pas descendre en dessous de '
                .SettingsRepository::ANONYMITY_FLOOR
                .' officines : les indicateurs deviendraient ceux d’une seule.',
            'minimum.max' => 'Un seuil au-delà de 100 officines masquerait tout le réseau.',
        ];
    }
}
