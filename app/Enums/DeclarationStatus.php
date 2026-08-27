<?php

namespace App\Enums;

enum DeclarationStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Rejected = 'rejected';

    /**
     * Work out the status implied by a pair of amounts.
     *
     * The single source of this rule: the model applies it on save and the
     * declaration request consults it to decide whether a delay is required.
     * Rejected is never derived — no pair of amounts implies a refusal.
     */
    public static function derive(int $invoiced, int $received): self
    {
        if ($received === 0) {
            return self::Unpaid;
        }

        return $received >= $invoiced ? self::Paid : self::Partial;
    }

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payé',
            self::Partial => 'Partiel',
            self::Unpaid => 'Non payé',
            self::Rejected => 'Rejeté',
        };
    }

    /**
     * Determine whether the status carries a payment delay.
     *
     * Only these two feed the average-delay statistics: an unpaid or rejected
     * invoice has no payment date to measure from.
     */
    public function isSettled(): bool
    {
        return $this === self::Paid || $this === self::Partial;
    }

    /**
     * Get the statuses that carry a payment delay.
     *
     * @return list<string>
     */
    public static function settledValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isSettled()),
        ));
    }
}
