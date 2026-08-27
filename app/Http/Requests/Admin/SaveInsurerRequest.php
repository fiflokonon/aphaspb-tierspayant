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

        // The management screen edits one field at a time, each in its own
        // small form: a rename must not have to resend the delay, nor the
        // reverse. Absent means unchanged, never « reset to the default ».
        return [
            'name' => [
                $insurer instanceof Insurer ? 'sometimes' : 'required',
                'string',
                'max:150',
                $insurer instanceof Insurer
                    ? Rule::unique(Insurer::class, 'name')->ignore($insurer->id)
                    : Rule::unique(Insurer::class, 'name'),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'standard_delay_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
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
            'standard_delay_days.integer' => 'Le délai standard se compte en jours entiers.',
            'standard_delay_days.min' => 'Le délai standard doit valoir au moins 1 jour.',
            'standard_delay_days.max' => 'Le délai standard ne peut pas dépasser 365 jours.',
        ];
    }
}
