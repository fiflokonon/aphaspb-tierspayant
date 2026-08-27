<?php

namespace App\Http\Requests\Admin;

use App\Models\Insurer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInsurerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $insurer = $this->route('insurer');

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                $insurer instanceof Insurer
                    ? Rule::unique(Insurer::class, 'name')->ignore($insurer->id)
                    : Rule::unique(Insurer::class, 'name'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => "Le nom de l'assureur est obligatoire.",
            'name.unique' => 'Un assureur porte déjà ce nom.',
        ];
    }
}
