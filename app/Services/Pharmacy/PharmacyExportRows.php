<?php

namespace App\Services\Pharmacy;

use App\Data\Period;
use App\Models\Declaration;
use App\Models\Pharmacy;
use Illuminate\Database\Eloquent\Collection;

/**
 * One officine's own declarations as rows, whatever file they end up in.
 *
 * The mirror image of NetworkExportRows, and deliberately not the same class:
 * that one may never name an officine and withholds anything below the
 * anonymity threshold, this one reads a single named officine and withholds
 * nothing from it. Merging them would put the confidentiality rule one
 * conditional away from being bypassed.
 *
 * The private note travels here. It is the officine's own writing, going back
 * to the officine — the rule that keeps it out of every admin route is exactly
 * why it may appear in this file.
 *
 * @phpstan-type ExportRow list<string|int|float|null>
 */
class PharmacyExportRows
{
    /** @var list<string> */
    public const COLUMNS = [
        'annee',
        'mois',
        'assureur',
        'statut',
        'facture_fcfa',
        'encaisse_fcfa',
        'reste_du_fcfa',
        'depot_facture',
        'dernier_versement',
        'delai_jours',
        'delai_standard_jours',
        'dans_le_delai',
        'versements',
        'detail_versements',
        'corrections',
        'note_privee',
    ];

    /**
     * One row per declaration, newest month first, without the header.
     *
     * @return iterable<int, ExportRow>
     */
    public function rows(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId = null): iterable
    {
        foreach ($this->declarations($pharmacy, $from, $to, $insurerId) as $declaration) {
            yield $this->row($declaration);
        }
    }

    /**
     * The declarations the file covers, with everything it renders preloaded.
     *
     * @return Collection<int, Declaration>
     */
    public function declarations(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId = null)
    {
        return Declaration::query()
            ->with(['insurer:id,name,standard_delay_days', 'payments'])
            ->withCount('revisions')
            ->where('pharmacy_id', $pharmacy->id)
            ->whereRaw(
                '(period_year * 12 + period_month) BETWEEN ? AND ?',
                [$from->toOrdinal(), $to->toOrdinal()],
            )
            ->when($insurerId, fn ($query) => $query->where('insurer_id', $insurerId))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('insurer_id')
            ->get();
    }

    /**
     * @return ExportRow
     */
    protected function row(Declaration $declaration): array
    {
        $standard = $declaration->insurer->standard_delay_days;

        return [
            $declaration->period_year,
            $declaration->period_month,
            $declaration->insurer->name,
            $declaration->status->label(),
            $declaration->amount_invoiced,
            $declaration->amount_received,
            $declaration->amount_outstanding,
            $declaration->invoice_deposited_on?->toDateString(),
            $declaration->paid_on?->toDateString(),
            $declaration->delay_days,
            $standard,
            $declaration->delay_days === null ? null : ($declaration->delay_days <= $standard ? 'oui' : 'non'),
            $declaration->payments->count(),
            // Les versements tiennent dans une cellule plutôt que d'éclater
            // chaque déclaration sur plusieurs lignes : le fichier reste une
            // ligne par mois et par assureur, donc triable et sommable.
            $this->renderInstalments($declaration),
            max(0, $declaration->revisions_count - 1),
            $declaration->private_note,
        ];
    }

    /**
     * The instalments as one readable cell: « 400000 le 2026-08-06 (5 j) ».
     */
    protected function renderInstalments(Declaration $declaration): ?string
    {
        if ($declaration->payments->isEmpty()) {
            return null;
        }

        return $declaration->payments->map(fn ($payment): string => sprintf(
            '%d le %s%s',
            $payment->amount,
            $payment->paid_on->toDateString(),
            $payment->delay_days === null ? '' : sprintf(' (%d j)', $payment->delay_days),
        ))->implode(' | ');
    }
}
