<?php

namespace App\Support;

use App\Data\PresentedNotification;
use App\Models\PharmacyInvitation;
use App\Notifications\Declarations\NetworkOverdueDigest;
use App\Notifications\Declarations\OverduePaymentsDigest;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Ramener chaque forme de notification à une seule, lisible par le front.
 *
 * Ajouter un type de notification se fait ici et nulle part ailleurs : le Vue
 * ne voit que des PresentedNotification.
 */
class NotificationPresenter
{
    public static function fromDatabase(DatabaseNotification $notification): PresentedNotification
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        [$title, $body, $href, $tone] = match ($notification->type) {
            OverduePaymentsDigest::class => self::overdueDigest($data),
            NetworkOverdueDigest::class => self::networkDigest($data),
            default => self::unknown(),
        };

        return new PresentedNotification(
            id: (string) $notification->id,
            kind: 'notification',
            title: $title,
            body: $body,
            href: $href,
            tone: $tone,
            createdAt: $notification->created_at?->toIso8601String() ?? '',
            readAt: $notification->read_at?->toIso8601String(),
        );
    }

    public static function fromInvitation(PharmacyInvitation $invitation): PresentedNotification
    {
        return new PresentedNotification(
            id: 'invitation-'.$invitation->id,
            kind: 'invitation',
            title: 'Invitation à rejoindre '.$invitation->pharmacy->name,
            body: sprintf(
                '%s vous invite à rejoindre son officine en tant que %s.',
                $invitation->inviter->name,
                mb_strtolower($invitation->role->label()),
            ),
            href: route('dashboard', [
                'current_pharmacy' => $invitation->pharmacy->slug,
            ], absolute: false),
            tone: 'neutral',
            createdAt: $invitation->created_at?->toIso8601String() ?? '',
            // Une invitation n'est jamais « lue » : elle est en attente ou elle
            // n'est plus là. Elle pèse donc toujours sur la pastille.
            readAt: null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: string|null, 3: string}
     */
    protected static function overdueDigest(array $data): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $data['lines'] ?? [];
        $count = count($lines);

        return [
            sprintf('%d facture%s au-delà du délai de paiement', $count, $count > 1 ? 's' : ''),
            sprintf(
                '%s FCFA restent dus à %s.',
                Fcfa::format((int) ($data['outstanding'] ?? 0)),
                (string) ($data['pharmacy_name'] ?? 'votre officine'),
            ),
            route('pharmacy.history', absolute: false),
            'alert',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: string|null, 3: string}
     */
    protected static function networkDigest(array $data): array
    {
        /** @var list<array<string, mixed>> $insurers */
        $insurers = $data['insurers'] ?? [];
        $count = count($insurers);

        return [
            'Retards de paiement du réseau',
            sprintf(
                '%s FCFA dus au-delà des délais, sur %d assureur%s.',
                Fcfa::format((int) ($data['outstanding'] ?? 0)),
                $count,
                $count > 1 ? 's' : '',
            ),
            route('admin.trends', absolute: false),
            'warn',
        ];
    }

    /**
     * Un type retiré du code laisse ses lignes en base : elles doivent rester
     * affichables plutôt que faire tomber la page.
     *
     * @return array{0: string, 1: string, 2: string|null, 3: string}
     */
    protected static function unknown(): array
    {
        return [
            'Notification',
            "Cette notification n'est plus affichable.",
            null,
            'neutral',
        ];
    }
}
