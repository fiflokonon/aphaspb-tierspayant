<?php

namespace App\Enums;

/**
 * Les huit critères du canevas d'évaluation annuelle de l'assureur.
 *
 * Le barème est celui du canevas APhaSPB : des points inégaux par critère,
 * vingt au total. La valeur de chaque cas est le nom de sa colonne dans
 * `insurer_evaluations`, et l'ordre des cas est l'ordre du canevas (a → h).
 */
enum EvaluationCriterion: string
{
    case Communication = 'communication';
    case ProductLists = 'product_lists';
    case OnlinePlatform = 'online_platform';
    case MedicalAdvisers = 'medical_advisers';
    case SettlementNotice = 'settlement_notice';
    case PaymentDelay = 'payment_delay';
    case RejectionReasons = 'rejection_reasons';
    case ClaimsHandling = 'claims_handling';

    public function label(): string
    {
        return match ($this) {
            self::Communication => 'Qualité de communication avec le prestataire',
            self::ProductLists => 'Mise à disposition et mise à jour des listes de produits couverts/non couverts',
            self::OnlinePlatform => 'Fonctionnalité de la plateforme en ligne',
            self::MedicalAdvisers => 'Disponibilité et réactivité des médecins conseils',
            self::SettlementNotice => 'Format de notification du détail des règlements',
            self::PaymentDelay => 'Respect du délai de règlement',
            self::RejectionReasons => 'Notification des motifs de rejet',
            self::ClaimsHandling => 'Prise en compte des réclamations',
        };
    }

    public function maxPoints(): int
    {
        return match ($this) {
            self::MedicalAdvisers, self::RejectionReasons => 3,
            self::PaymentDelay => 4,
            default => 2,
        };
    }

    /**
     * La lettre du canevas, de « a » à « h ».
     */
    public function letter(): string
    {
        return chr(ord('a') + array_search($this, self::cases(), true));
    }

    /**
     * Le total du canevas : 20 points.
     */
    public static function totalPoints(): int
    {
        return array_sum(array_map(fn (self $criterion): int => $criterion->maxPoints(), self::cases()));
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_map(fn (self $criterion): string => $criterion->value, self::cases());
    }

    /**
     * Le barème tel que les écrans le décrivent.
     *
     * @return list<array{key: string, letter: string, label: string, maxPoints: int}>
     */
    public static function scale(): array
    {
        return array_map(fn (self $criterion): array => [
            'key' => $criterion->value,
            'letter' => $criterion->letter(),
            'label' => $criterion->label(),
            'maxPoints' => $criterion->maxPoints(),
        ], self::cases());
    }
}
