<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Support\WhatsappNumber;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One officine seen from the network: who it is, where, and which insurers it
 * works with.
 *
 * Per insurer, a single yes/no — « still owes it money » — and never an
 * amount: the mirror of InsurerSheetController's creditor list, which names
 * the same pairs (decision of 15/10/2026, see InsurerCreditors).
 */
class PharmacySheetController extends Controller
{
    public function __invoke(Pharmacy $pharmacy): Response
    {
        $owing = DB::table('declarations')
            ->where('pharmacy_id', $pharmacy->id)
            ->whereColumn('amount_invoiced', '>', 'amount_received')
            ->distinct()
            ->pluck('insurer_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // Les assureurs cochés, plus ceux qui doivent encore alors que
        // l'officine les a décochés depuis : une dette ne s'arrête pas là.
        $insurers = Insurer::query()
            ->where(fn ($query) => $query
                ->whereIn('id', $owing)
                ->orWhereHas('pharmacies', fn ($inner) => $inner->whereKey($pharmacy->id)))
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('admin/PharmacySheet', [
            'pharmacy' => [
                'name' => $pharmacy->name,
                'city' => $pharmacy->city,
                'region' => $pharmacy->regionName(),
                'ownerName' => $pharmacy->owner_name,
                'phone' => $pharmacy->whatsapp_phone === null
                    ? null
                    : ['href' => 'tel:'.$pharmacy->whatsapp_phone, 'label' => WhatsappNumber::display($pharmacy->whatsapp_phone)],
                'registeredAt' => $pharmacy->created_at?->translatedFormat('j F Y'),
            ],
            'insurers' => $insurers->map(fn (Insurer $insurer) => [
                'id' => $insurer->id,
                'name' => $insurer->name,
                'owesMoney' => in_array($insurer->id, $owing, true),
            ]),
        ]);
    }
}
