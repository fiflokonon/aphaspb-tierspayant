{{--
    Le journal des pénalités d'une officine, mis en page pour dompdf.

    Mêmes contraintes moteur que le relevé : ni flexbox ni grid — les colonnes
    sont des tableaux —, `position: fixed` pour ce qui se répète de page en
    page, et `page-break-inside: avoid` sur les lignes. Le style est celui du
    relevé, pour que les deux documents se lisent comme une famille.
--}}
@php
    // Null se lit « — » : un mois sans chiffre n'est pas un mois à zéro.
    $money = fn (?int $value): string => $value === null ? '—' : \App\Support\Fcfa::format($value);
@endphp
<style>
    @page { margin: 112px 32px 62px; }

    body {
        margin: 0;
        color: #243333;
        font-family: "DejaVu Sans", sans-serif;
        font-size: 8.5px;
        line-height: 1.45;
    }

    .sheet-header {
        position: fixed;
        top: -88px; left: 0; right: 0;
        height: 70px;
        border-bottom: 1.4px solid #008f83;
    }

    .sheet-header .kicker {
        color: #008f83;
        font-size: 7.5px;
        font-weight: bold;
        letter-spacing: 1.1px;
        text-transform: uppercase;
    }

    .sheet-header .brand {
        margin-top: 1px;
        font-size: 15px;
        font-weight: bold;
        letter-spacing: -0.2px;
    }

    .sheet-header .scope { margin-top: 5px; color: #6b7878; font-size: 8px; }

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

    .lede { margin: 0 0 14px; color: #556161; font-size: 8.5px; }

    table { width: 100%; border-collapse: collapse; }

    .kpis td {
        width: 25%;
        padding: 9px 10px;
        border: 0.7px solid #dfe7e5;
        background: #f7f9f9;
        vertical-align: top;
    }

    .kpis.second td { background: #fff; }
    .kpis .value { font-size: 16px; font-weight: bold; }
    .kpis .unit { font-size: 8px; font-weight: normal; color: #6b7878; }
    .kpis .label {
        margin-top: 2px;
        color: #6b7878;
        font-size: 7px;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .grid { margin-top: 10px; }
    .grid th {
        padding: 5px 4px;
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
        padding: 5px 4px;
        border-bottom: 0.6px solid #eaf0ee;
        text-align: right;
    }
    .grid tr { page-break-inside: avoid; }
    .grid .name { font-weight: bold; }
    .grid .sub { color: #8b9797; font-size: 7px; }
    .grid .late { color: #b4472e; font-weight: bold; }
    .insurer-page { page-break-before: always; }
    .insurer-page h2 { margin-bottom: 2px; }

    .convention {
        margin: 0 0 10px;
        color: #445050;
        font-size: 8px;
    }

    .grid tfoot td {
        border-top: 1.1px solid #243333;
        border-bottom: 0;
        font-weight: bold;
    }

    .status {
        display: inline-block;
        padding: 1px 5px;
        border-radius: 7px;
        font-size: 6.8px;
        font-weight: bold;
    }
    .status-paid { background: #e8f6f3; color: #006f68; }
    .status-partial { background: #fff8e9; color: #ae7d20; }
    .status-unpaid { background: #f4eceb; color: #b4472e; }
    .status-rejected { background: #eceeee; color: #556161; }

    .note {
        margin-top: 12px;
        padding: 8px 10px;
        border-left: 2.4px solid #008f83;
        background: #f2faf8;
        color: #1d4a46;
        font-size: 7.5px;
    }

    .section { margin-top: 20px; }
    .section-break { page-break-before: always; }

    .grid .total td { font-weight: bold; }
    .grid .current { color: #8b9797; font-weight: normal; }
</style>

<div class="sheet-header">
    <div class="kicker">APhaSPB · Journal des pénalités</div>
    <div class="brand">{{ $pharmacyName }}</div>
    <div class="scope">{{ $periodLabel }}</div>
</div>

<div class="sheet-footer">
    <table>
        <tr>
            <td style="text-align: left;">
                Édité le {{ $generatedAt->translatedFormat('d/m/Y à H:i') }} ·
                Document interne à l'officine, établi à partir de ses seules
                déclarations.
            </td>
            <td style="text-align: right;">Page <span class="page-number"></span></td>
        </tr>
    </table>
</div>

<h2>Tous assureurs</h2>
<p class="lede">
    Pénalité courue : ce qui est tombé pendant le mois, toutes factures
    confondues. Factures du mois : la pénalité, à ce jour, des factures de ce
    mois déclaré. Le mois en cours est partiel.
</p>

<table class="grid">
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
                </td>
                <td>{{ $money($month->accrued) }}</td>
                <td>{{ $money($month->accruedCumulative) }}</td>
                <td>{{ $money($month->declared) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@foreach ($ledger->insurers as $series)
    <div class="section">
        <h2>{{ $series->name }}</h2>

        <table class="grid">
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
                        <td class="text">{{ $month->label }}</td>
                        <td>{{ $money($month->accrued) }}</td>
                        <td>{{ $money($month->accruedCumulative) }}</td>
                        <td>{{ $money($month->declared) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach
