<?php

namespace App\Http\Requests\Pharmacy;

use App\Enums\EvaluationCriterion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Les huit notes du canevas, chacune dans son barème.
 *
 * Toutes obligatoires, la note proposée pour le délai de règlement comprise :
 * c'est l'officine qui la valide, et une note absente n'est pas un zéro.
 */
class SaveInsurerEvaluationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (EvaluationCriterion::cases() as $criterion) {
            $rules[$criterion->value] = ['required', 'integer', 'min:0', 'max:'.$criterion->maxPoints()];
        }

        return $rules;
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (EvaluationCriterion::cases() as $criterion) {
            $messages[$criterion->value.'.required'] = 'Attribuez une note à ce critère.';
            $messages[$criterion->value.'.min'] = 'La note ne peut pas être négative.';
            $messages[$criterion->value.'.max'] = "Ce critère est noté sur {$criterion->maxPoints()} points.";
        }

        return $messages;
    }
}
