<?php

namespace App\Http\Requests\Admin;

use App\Data\Period;
use App\Services\Declarations\DeclarationRange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le plancher des déclarations et le rattrapage demandé aux nouvelles officines.
 *
 * Le plancher peut descendre aussi loin que l'admin le juge utile, mais pas
 * dans le futur : le mois en cours doit toujours rester déclarable.
 */
class SaveDeclarationRangeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'floor_year' => ['required', 'integer', 'min:2000'],
            'floor_month' => ['required', 'integer', 'between:1,12'],
            'catch_up_months' => ['required', 'integer', 'min:0', 'max:'.DeclarationRange::MAX_CATCH_UP_MONTHS],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->floor()->toOrdinal() > app(DeclarationRange::class)->current()->toOrdinal()) {
                $validator->errors()->add('floor_month', 'Le plancher ne peut pas être dans le futur.');
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
            'floor_year.required' => 'Indiquez l’année du plancher.',
            'floor_month.required' => 'Indiquez le mois du plancher.',
            'catch_up_months.required' => 'Indiquez le nombre de mois à rattraper.',
            'catch_up_months.max' => 'Le rattrapage ne peut pas dépasser '.DeclarationRange::MAX_CATCH_UP_MONTHS.' mois.',
        ];
    }

    public function floor(): Period
    {
        return new Period($this->integer('floor_year'), $this->integer('floor_month'));
    }
}
