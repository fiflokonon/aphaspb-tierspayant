<?php

namespace App\Http\Controllers\Notifications;

use App\Data\PresentedNotification;
use App\Http\Controllers\Controller;
use App\Models\PharmacyInvitation;
use App\Support\NotificationPresenter;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tout ce qui attend l'utilisateur, au même endroit.
 *
 * Deux sources s'y mélangent : les notifications du canal `database` et les
 * invitations en attente. Ces dernières ne peuvent pas être des notifications —
 * elles visent une adresse e-mail, et le canal `database` refuse un notifiable
 * anonyme — alors elles sont lues depuis leur propre table et normalisées par
 * NotificationPresenter comme n'importe quelle autre ligne.
 */
class NotificationCentreController extends Controller
{
    /** Le nombre de lignes affiché avant que l'utilisateur en choisisse un autre. */
    protected const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $perPage = PageSize::resolve($request, self::PER_PAGE);

        $invitations = $this->pendingInvitations($request);

        $rows = [
            ...$user->notifications()
                ->latest()
                ->get()
                ->map(fn (DatabaseNotification $one) => NotificationPresenter::fromDatabase($one))
                ->all(),
            ...$invitations
                ->map(fn (PharmacyInvitation $one) => NotificationPresenter::fromInvitation($one))
                ->all(),
        ];

        // Trié après la fusion, pas avant : les deux sources vivent dans des
        // tables différentes, et un ORDER BY de chaque côté entrelacerait mal
        // les dates.
        usort(
            $rows,
            fn (PresentedNotification $a, PresentedNotification $b): int => $b->createdAt <=> $a->createdAt,
        );

        return Inertia::render('notifications/Index', [
            'notifications' => [
                'items' => $this->paginate($rows, $perPage, $request),
                'unread' => $user->unreadNotifications()->count() + $invitations->count(),
                'perPage' => $perPage,
            ],
            'pageSizes' => PageSize::OPTIONS,
        ]);
    }

    public function update(Request $request, string $notification): RedirectResponse
    {
        // Cadré par la relation, jamais par l'id seul : sans ça n'importe qui
        // marquerait lue la notification d'un autre. Un 404 plutôt qu'un 403,
        // qui confirmerait l'existence de la ligne.
        $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail()
            ->markAsRead();

        return back();
    }

    /**
     * Les invitations qui attendent encore une réponse de cet utilisateur.
     *
     * @return Collection<int, PharmacyInvitation>
     */
    protected function pendingInvitations(Request $request): Collection
    {
        return PharmacyInvitation::query()
            ->with(['inviter', 'pharmacy'])
            ->pendingForEmail($request->user()->email)
            ->latest()
            ->get();
    }

    /**
     * Paginer une liste déjà construite en mémoire.
     *
     * Les deux sources vivent dans des tables différentes : une pagination
     * SQL découperait chacune de son côté et entrelacerait mal les dates.
     *
     * @param  list<PresentedNotification>  $rows
     * @return LengthAwarePaginator<int, PresentedNotification>
     */
    protected function paginate(array $rows, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
