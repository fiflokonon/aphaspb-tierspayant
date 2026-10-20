<?php

namespace App\Http\Requests\Admin;

use App\Models\EvaluationCampaign;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Une campagne : l'année évaluée et la fenêtre de réponse.
 *
 * Deux campagnes ne se chevauchent jamais, pour qu'une officine n'ait
 * jamais qu'une seule campagne ouverte devant elle.
 */
class SaveEvaluationCampaignRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|Unique>>
     */
    public function rules(): array
    {
        return [
            'year' => [
                'required',
                'integer',
                'min:2020',
                // L'année en cours se laisse évaluer, pas une année à venir :
                // ses déclarations n'existent pas encore.
                'max:'.now()->year,
                Rule::unique(EvaluationCampaign::class, 'year')->ignore($this->campaignId()),
            ],
            'opens_on' => ['required', 'date'],
            'closes_on' => ['required', 'date', 'after_or_equal:opens_on'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $overlaps = EvaluationCampaign::query()
                ->when($this->campaignId(), fn ($query, int $id) => $query->whereKeyNot($id))
                ->whereDate('opens_on', '<=', $this->date('closes_on'))
                ->whereDate('closes_on', '>=', $this->date('opens_on'))
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('opens_on', 'Ces dates chevauchent une autre campagne.');
            }
        });
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'year.required' => 'Indiquez l’année évaluée.',
            'year.max' => 'Une année à venir ne peut pas encore être évaluée.',
            'year.unique' => 'Une campagne existe déjà pour cette année.',
            'opens_on.required' => 'Indiquez la date d’ouverture.',
            'closes_on.required' => 'Indiquez la date de clôture.',
            'closes_on.after_or_equal' => 'La clôture ne peut pas précéder l’ouverture.',
        ];
    }

    protected function campaignId(): ?int
    {
        $campaign = $this->route('campaign');

        return $campaign instanceof EvaluationCampaign ? $campaign->id : null;
    }
}
