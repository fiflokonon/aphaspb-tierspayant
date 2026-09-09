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
            'status' => ['nullable', new Enum(DeclarationStatus::class)],
            // A monthly invoice cannot be filed before the month it covers, and
            // it cannot be dated in the future.
            'invoice_deposited_on' => [
                'required',
                'date',
                'before_or_equal:today',
                ...($this->declaredMonthStart() === null
                    ? []
                    : ['after_or_equal:'.$this->declaredMonthStart()]),
            ],
            // An insurer settles a month in one transfer or several. Each line
            // carries its own date — that is the whole point of the list, and
            // it is what keeps a recorded amount from ever losing the date its
            // delay is measured from.
            // Plafonnée : chaque ligne devient une ligne en base, et rien du
            // côté client ne borne ce que la requête transporte. Deux douzaines
            // de virements sur un seul mois de facturation est déjà au-delà de
            // ce qu'un assureur produit.
            'payments' => ['array', 'max:24'],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.paid_on' => [
                'required',
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
     * What the instalments as a whole are allowed to add up to.
     *
     * Each line is judged on its own by the rules above; only the total needs
     * the invoice to compare itself against, and only the status can say
     * whether any transfer should be there at all.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['amount_invoiced', 'payments'])) {
                return;
            }

            $received = $this->amountReceived();

            if ($received > $this->integer('amount_invoiced')) {
                $validator->errors()->add(
                    'payments',
                    'Le total des versements ne peut pas dépasser le montant facturé.',
                );

                return;
            }

            // Le statut et les versements doivent se répondre, dans les deux
            // sens. `status` est un champ ouvert sur une route publique : sans
            // le second test, un POST forgé posait une déclaration « payée »
            // sans un franc ni une date, que les agrégats réseau comptaient
            // comme réglée tout en la privant de délai — moyenne et part dans
            // les clous sous-estimées, en silence.
            $settled = $this->resolvedStatus()->isSettled();

            if ($received > 0 && ! $settled) {
                $validator->errors()->add(
                    'payments',
                    "Un versement n'a pas de sens sur une facture déclarée rejetée.",
                );

                return;
            }

            if ($received === 0 && $settled && $this->input('status') !== null) {
                $validator->errors()->add(
                    'payments',
                    'Un statut « payé » ou « partiel » suppose au moins un versement.',
                );
            }
        });
    }

    /**
     * The instalments as the action expects them, in the order submitted.
     *
     * Anything malformed is dropped rather than cast. The after() callback that
     * totals these runs even when the per-line rules have already failed, so
     * this is reached with whatever the client sent — a list of scalars, a
     * string, a line missing its date. Each of those already carries its own
     * error; casting them here would only turn a 422 into a 500.
     *
     * @return list<array{amount: int, paid_on: string}>
     */
    public function instalments(): array
    {
        $lines = $this->input('payments');

        if (! is_array($lines)) {
            return [];
        }

        $instalments = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $amount = $line['amount'] ?? null;
            $paidOn = $line['paid_on'] ?? null;

            if (! is_numeric($amount) || ! is_string($paidOn)) {
                continue;
            }

            $instalments[] = [
                'amount' => (int) $amount,
                'paid_on' => $paidOn,
            ];
        }

        return $instalments;
    }

    /**
     * What the instalments add up to.
     *
     * The received amount is no longer typed in: it is the sum of the lines,
     * both here and on the model. Reading it from the request would be a
     * second source of truth for the same number.
     */
    public function amountReceived(): int
    {
        return array_sum(array_column($this->instalments(), 'amount'));
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
            $this->integer('amount_invoiced'),
            $this->amountReceived(),
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
            $this->integer('amount_invoiced'),
            $this->amountReceived(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'insurer_id.exists' => 'Cet assureur ne fait pas partie de ceux que vous avez cochés.',
            'invoice_deposited_on.required' => 'Indiquez la date de dépôt de la facture.',
            'invoice_deposited_on.before_or_equal' => 'La date de dépôt ne peut pas être dans le futur.',
            'invoice_deposited_on.after_or_equal' => 'La facture ne peut pas avoir été déposée avant le mois déclaré.',
            'payments.max' => 'Une déclaration mensuelle ne peut pas porter plus de 24 versements.',
            'payments.*.amount.required' => 'Indiquez le montant de ce versement.',
            'payments.*.amount.min' => 'Un versement porte forcément sur un montant.',
            'payments.*.paid_on.required' => 'Indiquez la date de ce versement.',
            'payments.*.paid_on.before_or_equal' => 'La date de paiement ne peut pas être dans le futur.',
            'payments.*.paid_on.after_or_equal' => 'Le paiement ne peut pas précéder le dépôt de la facture.',
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
