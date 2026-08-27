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
            // A monthly invoice cannot be filed before the month it covers, and
            // neither date can be in the future. The pair is required or
            // forbidden depending on the status: see withValidator().
            'invoice_deposited_on' => [
                'nullable',
                'date',
                'before_or_equal:today',
                ...($this->declaredMonthStart() === null
                    ? []
                    : ['after_or_equal:'.$this->declaredMonthStart()]),
            ],
            'paid_on' => [
                'nullable',
                'date',
                'before_or_equal:today',
                'after_or_equal:invoice_deposited_on',
            ],
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
     * The first day of the declared month, or null when the pair is unusable.
     *
     * Only used to bound the deposit date; DeclarablePeriod judges the period
     * itself, and a nonsensical pair must not raise a second, confusing error.
     */
    public function declaredMonthStart(): ?string
    {
        $year = $this->integer('period_year');
        $month = $this->integer('period_month');

        if ($year < 2000 || $month < 1 || $month > 12) {
            return null;
        }

        return sprintf('%04d-%02d-01', $year, $month);
    }

    /**
     * The dates only exist where a payment did.
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
            $deposited = $this->input('invoice_deposited_on');
            $paid = $this->input('paid_on');

            if ($status->isSettled()) {
                if ($deposited === null || $deposited === '') {
                    $validator->errors()->add('invoice_deposited_on', 'Indiquez la date de dépôt de la facture.');
                }

                if ($paid === null || $paid === '') {
                    $validator->errors()->add('paid_on', 'Indiquez la date de paiement.');
                }

                return;
            }

            if ($paid !== null && $paid !== '') {
                $validator->errors()->add('paid_on', "Une date de paiement n'a de sens que si un paiement a été reçu.");
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
            'invoice_deposited_on.before_or_equal' => 'La date de dépôt ne peut pas être dans le futur.',
            'invoice_deposited_on.after_or_equal' => 'La facture ne peut pas avoir été déposée avant le mois déclaré.',
            'paid_on.before_or_equal' => 'La date de paiement ne peut pas être dans le futur.',
            'paid_on.after_or_equal' => 'Le paiement ne peut pas précéder le dépôt de la facture.',
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
