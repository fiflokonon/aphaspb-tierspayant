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
            // Les deux moitiés de la clause voyagent ensemble, à rebours de la
            // règle « un champ, un formulaire » qui vaut pour le reste de cet
            // écran : un déclenchement sans taux n'accumule rien, un taux sans
            // déclenchement ne démarre jamais. required_with tient donc dans
            // les deux sens, et les deux vides effacent la clause.
            // Pas de `sometimes` ici, contrairement aux trois règles du
            // dessus : `sometimes` court-circuite la validation d'un champ
            // absent, et c'est justement l'absence de l'un quand l'autre est
            // là que `required_with` doit refuser. Les deux absents restent
            // acceptés — `nullable` passe et `required_with` ne mord pas —,
            // ce qui laisse un simple renommage traverser sans toucher la
            // clause.
            'penalty_trigger_days' => ['nullable', 'integer', 'min:1', 'max:365', 'required_with:penalty_rate_percent'],
            'penalty_rate_percent' => ['nullable', 'numeric', 'min:0.01', 'max:100', 'required_with:penalty_trigger_days'],
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
            'penalty_trigger_days.required_with' => 'Indiquez à partir de combien de jours la pénalité se déclenche.',
            'penalty_trigger_days.integer' => 'Le délai de déclenchement se compte en jours entiers.',
            'penalty_trigger_days.min' => 'Le délai de déclenchement doit valoir au moins 1 jour.',
            'penalty_trigger_days.max' => 'Le délai de déclenchement ne peut pas dépasser 365 jours.',
            'penalty_rate_percent.required_with' => 'Indiquez le taux de pénalité prévu par la convention.',
            'penalty_rate_percent.numeric' => 'Le taux de pénalité est un pourcentage.',
            'penalty_rate_percent.min' => 'Le taux de pénalité doit être supérieur à zéro.',
            'penalty_rate_percent.max' => 'Le taux de pénalité ne peut pas dépasser 100 %.',
        ];
    }
}
