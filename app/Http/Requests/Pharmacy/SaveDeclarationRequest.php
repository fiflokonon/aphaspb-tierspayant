<?php

namespace App\Http\Requests\Pharmacy;

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Rules\DeclarablePeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SaveDeclarationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Enum|string>>
     */
    public function rules(): array
    {
        // Read the column, not the relation: it is unambiguously nullable and
        // needs no query. Zero can match no pharmacy, so an unset officine
        // simply fails the exists rule.
        $pharmacyId = $this->user()->current_pharmacy_id ?? 0;

        return [
            'insurer_id' => [
                'required',
                'integer',
                // Existing is not enough: it has to be one this officine ticked.
                Rule::exists('insurer_pharmacy', 'insurer_id')
                    ->where('pharmacy_id', $pharmacyId),
            ],
            'period_year' => ['required', 'integer'],
            'period_month' => ['required', 'integer'],
            'period' => [new DeclarablePeriod],
            'amount_invoiced' => ['required', 'integer', 'min:0'],
            'amount_received' => ['required', 'integer', 'min:0', 'lte:amount_invoiced'],
            'status' => ['nullable', new Enum(DeclarationStatus::class)],
            'delay_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'private_note' => ['nullable', 'string', 'max:150'],
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
     * A delay only exists where a payment did.
     *
     * The condition consults DeclarationStatus::derive(), the same rule the
     * model applies on save, so the two can never drift apart.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['amount_invoiced', 'amount_received'])) {
                return;
            }

            $status = $this->resolvedStatus();
            $delay = $this->input('delay_days');

            if ($status->isSettled() && ($delay === null || $delay === '')) {
                $validator->errors()->add('delay_days', 'Indiquez le délai de paiement en jours.');
            }

            if (! $status->isSettled() && $delay !== null && $delay !== '') {
                $validator->errors()->add('delay_days', "Un délai n'a de sens que si un paiement a été reçu.");
            }
        });
    }

    /**
     * The status this submission ends up with: the explicit one, or the derived.
     */
    public function resolvedStatus(): DeclarationStatus
    {
        $explicit = $this->input('status');

        if ($explicit !== null && $explicit !== '') {
            return DeclarationStatus::from((string) $explicit);
        }

        return DeclarationStatus::derive(
            (int) $this->input('amount_invoiced'),
            (int) $this->input('amount_received'),
        );
    }

    /**
     * Whether the pharmacist overrode the derived status by hand.
     */
    public function isStatusManual(): bool
    {
        $explicit = $this->input('status');

        if ($explicit === null || $explicit === '') {
            return false;
        }

        return DeclarationStatus::from((string) $explicit) !== DeclarationStatus::derive(
            (int) $this->input('amount_invoiced'),
            (int) $this->input('amount_received'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'insurer_id.exists' => 'Cet assureur ne fait pas partie de ceux que vous avez cochés.',
            'amount_received.lte' => 'Le montant reçu ne peut pas dépasser le montant facturé.',
        ];
    }

    /**
     * Keep the constant reachable from the request for the period bounds.
     */
    public function earliestMonthsBack(): int
    {
        return Declaration::EARLIEST_MONTHS_BACK;
    }
}
