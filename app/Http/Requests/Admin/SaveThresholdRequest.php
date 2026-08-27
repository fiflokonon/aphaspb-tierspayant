<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The regulatory payment threshold, and nothing else.
 *
 * Deliberately not a generic « update a setting by key » request: such a route
 * would be shorter to write and would make the anonymity threshold writable by
 * a forged request. An admin who could lower it to 1 would read the indicators
 * of an insurer declared by a single officine — and therefore identify it. The
 * CDC only ever presents the thirty-day threshold as adjustable.
 */
class SaveThresholdRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'payment_delay_threshold_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_delay_threshold_days.required' => 'Indiquez un seuil en jours.',
            'payment_delay_threshold_days.min' => 'Le seuil doit valoir au moins 1 jour.',
            'payment_delay_threshold_days.max' => 'Le seuil ne peut pas dépasser 365 jours.',
        ];
    }
}
