<?php

namespace App\Enums;

enum DeclarationStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Rejected = 'rejected';

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
