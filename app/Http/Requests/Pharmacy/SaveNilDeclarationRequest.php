<?php

namespace App\Http\Requests\Pharmacy;

use App\Models\Declaration;
use App\Rules\DeclarablePeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * « Pas de facture ce mois », for one insurer or for every one still open.
 *
 * Without an insurer the request covers the month's remaining insurers: the
 * dashboard's catch-up list offers it for a month left behind entirely.
 */
class SaveNilDeclarationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Exists|string>>
     */
    public function rules(): array
    {
        $pharmacyId = $this->user()->current_pharmacy_id ?? 0;

        return [
            'insurer_id' => [
                'nullable',
                'integer',
                Rule::exists('insurer_pharmacy', 'insurer_id')
                    ->where('pharmacy_id', $pharmacyId),
            ],
            'period_year' => ['required', 'integer'],
            'period_month' => ['required', 'integer'],
            'period' => [new DeclarablePeriod],
        ];
    }

    /**
     * Feed the period pair to DeclarablePeriod as a single value.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [
            ...$this->all(),
            'period' => [$this->input('period_year'), $this->input('period_month')],
        ];
    }

    /**
     * An insurer whose invoice is already declared cannot be reported as
     * having none: the figures would say one thing and the answer another.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->integer('insurer_id') === 0) {
                return;
            }

            $declared = Declaration::query()
                ->where('pharmacy_id', $this->user()->current_pharmacy_id)
                ->where('insurer_id', $this->integer('insurer_id'))
                ->where('period_year', $this->integer('period_year'))
                ->where('period_month', $this->integer('period_month'))
                ->exists();

            if ($declared) {
                $validator->errors()->add('insurer_id', 'Une facture est déjà déclarée pour cet assureur ce mois-ci.');
            }
        });
    }
}
