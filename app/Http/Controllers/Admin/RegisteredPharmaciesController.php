<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The officines registered on the platform.
 *
 * The one admin screen where an officine's identity is allowed: the CDC lists
 * « nom, ville, date d'inscription » explicitly, and forbids in the same breath
 * any access to their declarations or amounts. The rule is therefore not « no
 * officine names » but « nothing about a declaration next to a name ».
 *
 * This controller queries Pharmacy directly and never touches declarations —
 * that is what keeps the promise structural rather than a matter of care. It
 * deliberately exposes no per-officine « has declared » flag: that is
 * declaration data, and the network figure stays on screen 2a.
 */
class RegisteredPharmaciesController extends Controller
{
    protected const PER_PAGE = 50;

    public function __invoke(Request $request): Response
    {
        $city = $request->string('city')->value() ?: null;
        $search = $request->string('search')->value() ?: null;

        $pharmacies = Pharmacy::query()
            ->when($city, fn ($query) => $query->where('city', $city))
            ->when($search, fn ($query) => $query->whereRaw(
                'LOWER(name) LIKE ?',
                ['%'.mb_strtolower($search).'%'],
            ))
            ->orderBy('name')
            ->paginate(self::PER_PAGE, ['id', 'name', 'city', 'onpb_license', 'created_at'])
            ->withQueryString()
            ->through(fn (Pharmacy $pharmacy) => [
                'id' => $pharmacy->id,
                'name' => $pharmacy->name,
                'city' => $pharmacy->city,
                'onpbLicense' => $pharmacy->onpb_license,
                'registeredAt' => $pharmacy->created_at?->translatedFormat('j F Y'),
            ]);

        return Inertia::render('admin/Pharmacies', [
            'pharmacies' => $pharmacies,
            'total' => Pharmacy::query()->count(),
            'cities' => Pharmacy::query()
                ->whereNotNull('city')
                ->distinct()
                ->orderBy('city')
                ->pluck('city')
                ->all(),
            'filters' => ['city' => $city, 'search' => $search],
        ]);
    }
}
