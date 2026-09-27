{{--
    Le journal des pénalités du réseau, mis en page pour dompdf.

    Mêmes contraintes moteur que le rapport réseau : tableaux seulement,
    `position: fixed` pour l'en-tête et le pied, lignes insécables. Aucune
    rétention n'est décidée ici : les mois retenus arrivent déjà vidés de
    NetworkPenaltyJournal, et s'écrivent « retenu ».
--}}
@php
    // Retenu d'abord : un null retenu n'est pas un « — » (rien à dire).
    $money = fn (\App\Data\PenaltyLedgerMonth $month, ?int $value): string => $month->withheld
        ? 'retenu'
        : ($value === null ? '—' : \App\Support\Fcfa::format($value));
    // Un cumul vide sur un mois publié vient d'un mois retenu plus tôt.
    $cumulative = fn (\App\Data\PenaltyLedgerMonth $month): string => ! $month->withheld && $month->accruedCumulative === null
        ? 'interrompu'
        : $money($month, $month->accruedCumulative);
@endphp
<style>
    @page { margin: 108px 34px 62px; }

    body {
        margin: 0;
        color: #243333;
        font-family: "DejaVu Sans", sans-serif;
        font-size: 8.5px;
        line-height: 1.45;
    }

    .sheet-header {
        position: fixed;
        top: -84px; left: 0; right: 0;
        height: 66px;
        border-bottom: 1.4px solid #008f83;
    }

    .sheet-header .brand {
        font-size: 15px;
        font-weight: bold;
        letter-spacing: -0.2px;
    }

    .sheet-header .kicker {
        margin-top: 1px;
        color: #008f83;
        font-size: 7.5px;
        font-weight: bold;
        letter-spacing: 1.1px;
        text-transform: uppercase;
    }

    .sheet-header .scope {
        margin-top: 5px;
        color: #6b7878;
        font-size: 8px;
    }

    .sheet-footer {
        position: fixed;
        bottom: -44px; left: 0; right: 0;
        padding-top: 6px;
        border-top: 0.6px solid #dfe7e5;
        color: #8b9797;
        font-size: 7px;
    }

    .sheet-footer .page-number:after { content: counter(page); }

    h2 {
        margin: 0 0 7px;
        font-size: 9.5px;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }

    .lede {
        margin: 0 0 14px;
        color: #556161;
        font-size: 8.5px;
    }

    table { width: 100%; border-collapse: collapse; }

    .kpis td {
        width: 25%;
        padding: 9px 10px;
        border: 0.7px solid #dfe7e5;
        background: #f7f9f9;
        vertical-align: top;
    }

    .kpis .value { font-size: 16px; font-weight: bold; }
    .kpis .unit { font-size: 8px; font-weight: normal; color: #6b7878; }
    .kpis .label {
        margin-top: 2px;
        color: #6b7878;
        font-size: 7px;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .grid { margin-top: 16px; }
    .grid th {
        padding: 5px 5px;
        border-bottom: 1.1px solid #243333;
        color: #445050;
        font-size: 6.8px;
        font-weight: bold;
        letter-spacing: 0.4px;
        text-align: right;
        text-transform: uppercase;
    }
    .grid th.text, .grid td.text { text-align: left; }
    .grid td {
        padding: 6px 5px;
        border-bottom: 0.6px solid #eaf0ee;
        text-align: right;
    }
    .grid tr { page-break-inside: avoid; }
    .grid .name { font-weight: bold; }
    .grid .sub { color: #8b9797; font-size: 7px; }
    .grid .late { color: #b4472e; font-weight: bold; }
    .grid .withheld td {
        color: #8b9797;
        font-style: italic;
        background: #fbfcfc;
    }

    .note {
        margin-top: 12px;
        padding: 8px 10px;
        border-left: 2.4px solid #d7a33d;
        background: #fff8e9;
        color: #5a4a24;
        font-size: 7.5px;
    }

    .section { margin-top: 22px; page-break-inside: avoid; }

    .insurer-page { page-break-before: always; }
    .insurer-page h2 { margin-bottom: 2px; }

    .convention {
        margin: 0 0 10px;
        color: #445050;
        font-size: 8px;
    }

    .ledger { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .ledger th {
        padding: 5px 4px;
        border-bottom: 1.1px solid #243333;
        font-size: 6.8px;
        font-weight: bold;
        letter-spacing: 0.4px;
        text-align: right;
        text-transform: uppercase;
    }
    .ledger td { padding: 5px 4px; border-bottom: 0.6px solid #eaf0ee; text-align: right; }
    .ledger th.text, .ledger td.text { text-align: left; }
    .ledger tr { page-break-inside: avoid; }
    .ledger .total td { font-weight: bold; }
    .ledger .current, .ledger .withheld { color: #8b9797; font-weight: normal; }
    .ledger-section { margin-top: 18px; }
</style>

<div class="sheet-header">
    <div class="kicker">APhaSPB · Journal des pénalités</div>
    <div class="brand">Réseau des officines</div>
    <div class="scope">
        {{ $periodLabel }}
        @if ($city) · officines de {{ $city }} @else · toutes les officines @endif
        · séries par assureur retenues sous {{ $anonymityThreshold }} officines
    </div>
</div>

<div class="sheet-footer">
    <table>
        <tr>
            <td style="text-align: left;">
                Édité le {{ $generatedAt->translatedFormat('d/m/Y à H:i') }} ·
                Aucune officine nommée. Le total, lui, n'applique pas le seuil
                d'anonymat (décision du 27/09/2026) : restreint à une ville ou à
                un mois, il peut ne reposer que sur quelques officines.
            </td>
            <td style="text-align: right;">Page <span class="page-number"></span></td>
        </tr>
    </table>
</div>

<h2>{{ $ledger->total->name }}</h2>
<p class="lede">
    Pénalité courue : ce qui est tombé pendant le mois, toutes factures
    confondues. Factures du mois : la pénalité, à ce jour, des factures de ce
    mois déclaré. Le mois en cours est partiel.
    @if ($ledger->maskedInsurers > 0)
        Le total couvre aussi {{ $ledger->maskedInsurers }} assureur(s) masqué(s)
        sous le seuil d'anonymat, qui n'ont pas de page propre.
    @endif
</p>

<table class="ledger">
    <thead>
        <tr>
            <th class="text">Mois</th>
            <th>Pénalité courue</th>
            <th>Cumul couru</th>
            <th>Factures du mois</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($ledger->total->months as $month)
            @continue($month->future)
            <tr class="total">
                <td class="text">
                    {{ $month->label }}
                    @if ($month->current) <span class="current">· en cours</span> @endif
                    @if ($month->withheld) <span class="withheld">· sous le seuil</span> @endif
                </td>
                <td>{{ $money($month, $month->accrued) }}</td>
                <td>{{ $cumulative($month) }}</td>
                <td>{{ $money($month, $month->declared) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@foreach ($ledger->insurers as $series)
    <div class="ledger-section">
        <h2>{{ $series->name }}</h2>

        <table class="ledger">
            <thead>
                <tr>
                    <th class="text">Mois</th>
                    <th>Pénalité courue</th>
                    <th>Cumul couru</th>
                    <th>Factures du mois</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($series->months as $month)
                    @continue($month->future)
                    <tr>
                        <td class="text">
                            {{ $month->label }}
                            @if ($month->current) <span class="current">· en cours</span> @endif
                            @if ($month->withheld) <span class="withheld">· sous le seuil</span> @endif
                        </td>
                        <td>{{ $money($month, $month->accrued) }}</td>
                        <td>{{ $cumulative($month) }}</td>
                        <td>{{ $money($month, $month->declared) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach
