<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\Region;
use App\Services\Network\RegionScopes;
use App\Support\PageSize;
use App\Support\WhatsappNumber;
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
 * The WhatsApp number is a contact detail, not declaration data: it sits on
 * the same side of that line as the name and the city.
 *
 * This controller queries Pharmacy directly and never touches declarations —
 * that is what keeps the promise structural rather than a matter of care. It
 * exposes no per-officine « has declared » flag: that flag lives, as the one
 * bounded exception to the CDC (spec suivi des déclarations, 28/09/2026), in
 * DeclarationFollowUpController and nowhere else.
 */
class RegisteredPharmaciesController extends Controller
{
    /** The size the roll opens at, until the admin picks another. */
    protected const PER_PAGE = 50;

    public function __invoke(Request $request, RegionScopes $regions): Response
    {
        $city = $request->string('city')->value() ?: null;
        // Une ville choisie prime sur les régions, comme sur les écrans réseau.
        $scope = $city === null ? $regions->fromIds($regions->requestedIds($request)) : null;
        $search = $request->string('search')->value() ?: null;
        $perPage = PageSize::resolve($request, self::PER_PAGE);

        $pharmacies = Pharmacy::query()
            ->when($city, fn ($query) => $query->where('city', $city))
            ->when($scope, fn ($query) => $query->whereIn('city', $scope->cities ?? []))
            ->when($search, fn ($query) => $query->whereRaw(
                'LOWER(name) LIKE ?',
                ['%'.mb_strtolower($search).'%'],
            ))
            ->select(['id', 'name', 'slug', 'city', 'whatsapp_phone', 'created_at'])
            // La région se lit sur la ville, en SQL : une sous-requête par
            // page, pas une par ligne.
            ->addSelect(['region' => Region::query()
                ->select('regions.name')
                ->join('cities', 'cities.region_id', '=', 'regions.id')
                ->whereColumn('cities.name', 'pharmacies.city')
                ->limit(1)])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Pharmacy $pharmacy) => [
                'id' => $pharmacy->id,
                'name' => $pharmacy->name,
                'slug' => $pharmacy->slug,
                'city' => $pharmacy->city,
                'region' => $pharmacy->getAttribute('region'),
                'phone' => $pharmacy->whatsapp_phone === null
                    ? null
                    : ['href' => 'tel:'.$pharmacy->whatsapp_phone, 'label' => WhatsappNumber::display($pharmacy->whatsapp_phone)],
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
            'regions' => $regions->options(),
            'filters' => [
                'city' => $city,
                'regions' => $scope->regionIds ?? [],
                'search' => $search,
                'perPage' => $perPage,
            ],
            'pageSizes' => PageSize::OPTIONS,
        ]);
    }
}
