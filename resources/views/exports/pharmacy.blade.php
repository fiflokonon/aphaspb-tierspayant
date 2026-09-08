{{--
    Le relevé d'une officine, mis en page pour dompdf.

    Mêmes contraintes moteur que le rapport réseau : ni flexbox ni grid — les
    colonnes sont des tableaux —, `position: fixed` pour ce qui se répète de
    page en page, et `page-break-inside: avoid` sur les lignes.
--}}
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
</style>

<div class="sheet-header">
    <div class="kicker">APhaSPB · Relevé tiers-payant</div>
    <div class="brand">{{ $pharmacy->name }}</div>
    <div class="scope">
        {{ $periodLabel }}
        @if ($insurerFilter) · {{ $insurerFilter }} uniquement @endif
        @if ($pharmacy->city) · {{ $pharmacy->city }} @endif
        @if ($pharmacy->owner_name) · titulaire {{ $pharmacy->owner_name }} @endif
    </div>
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

<h2>Où en est votre tiers-payant</h2>

<p class="lede">
    {{ $totals['declarations'] }} déclarations auprès de
    {{ $totals['insurers'] }} assureur{{ $totals['insurers'] > 1 ? 's' : '' }}
    sur la période. Le délai se compte du dépôt de la facture au dernier
    versement reçu.
</p>

<table class="kpis">
    <tr>
        <td>
            <div class="value">{{ \App\Support\Fcfa::format($totals['invoiced']) }}<span class="unit"> F</span></div>
            <div class="label">Facturé</div>
        </td>
        <td>
            <div class="value">{{ \App\Support\Fcfa::format($totals['received']) }}<span class="unit"> F</span></div>
            <div class="label">Encaissé</div>
        </td>
        <td>
            <div class="value">{{ \App\Support\Fcfa::format($totals['outstanding']) }}<span class="unit"> F</span></div>
            <div class="label">Reste dû</div>
        </td>
        <td>
            <div class="value">
                {{ $totals['recoveryRate'] === null ? '—' : number_format($totals['recoveryRate'], 1, ',', ' ') }}<span class="unit"> %</span>
            </div>
            <div class="label">Taux de recouvrement</div>
        </td>
    </tr>
</table>

<table class="kpis second" style="margin-top: 8px;">
    <tr>
        <td>
            <div class="value">
                {{ $totals['averageDelayDays'] === null ? '—' : number_format($totals['averageDelayDays'], 1, ',', ' ') }}<span class="unit"> j</span>
            </div>
            <div class="label">Délai moyen</div>
        </td>
        <td>
            <div class="value">
                {{ $totals['withinStandard'] === null ? '—' : number_format($totals['withinStandard'], 1, ',', ' ') }}<span class="unit"> %</span>
            </div>
            <div class="label">Réglées dans le délai standard</div>
        </td>
        <td>
            <div class="value">{{ $totals['instalments'] }}</div>
            <div class="label">Versements reçus</div>
        </td>
        <td>
            <div class="value">{{ $totals['splitSettlements'] }}</div>
            <div class="label">Mois réglés en plusieurs fois</div>
        </td>
    </tr>
</table>

<div class="section">
    <h2>Par assureur</h2>

    <p class="lede">
        Classés par reste dû décroissant : la première ligne est celle qui pèse
        le plus sur votre trésorerie.
    </p>

    <table class="grid">
        <thead>
            <tr>
                <th class="text">Assureur</th>
                <th>Décl.</th>
                <th>Facturé</th>
                <th>Encaissé</th>
                <th>Reste dû</th>
                <th>Recouvrement</th>
                <th>Délai moyen</th>
                <th>Versements</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($perInsurer as $row)
                <tr>
                    <td class="text">
                        <div class="name">{{ $row['name'] }}</div>
                        <div class="sub">standard {{ $row['standardDelayDays'] }} j</div>
                    </td>
                    <td>{{ $row['declarations'] }}</td>
                    <td>{{ \App\Support\Fcfa::format($row['invoiced']) }}</td>
                    <td>{{ \App\Support\Fcfa::format($row['received']) }}</td>
                    <td>{{ \App\Support\Fcfa::format($row['outstanding']) }}</td>
                    <td>{{ $row['recoveryRate'] === null ? '—' : number_format($row['recoveryRate'], 1, ',', ' ').' %' }}</td>
                    <td class="{{ $row['averageDelayDays'] !== null && $row['averageDelayDays'] > $row['standardDelayDays'] ? 'late' : '' }}">
                        {{ $row['averageDelayDays'] === null ? '—' : number_format($row['averageDelayDays'], 1, ',', ' ').' j' }}
                    </td>
                    <td>
                        {{ $row['instalments'] }}
                        <div class="sub">
                            {{ $row['splitSettlements'] }} mois fractionné{{ $row['splitSettlements'] > 1 ? 's' : '' }}
                        </div>
                    </td>
                </tr>
            @endforeach

            @if (count($perInsurer) === 0)
                <tr><td class="text" colspan="8">Aucune déclaration sur la période retenue.</td></tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td class="text">Total</td>
                <td>{{ $totals['declarations'] }}</td>
                <td>{{ \App\Support\Fcfa::format($totals['invoiced']) }}</td>
                <td>{{ \App\Support\Fcfa::format($totals['received']) }}</td>
                <td>{{ \App\Support\Fcfa::format($totals['outstanding']) }}</td>
                <td>{{ $totals['recoveryRate'] === null ? '—' : number_format($totals['recoveryRate'], 1, ',', ' ').' %' }}</td>
                <td>{{ $totals['averageDelayDays'] === null ? '—' : number_format($totals['averageDelayDays'], 1, ',', ' ').' j' }}</td>
                <td>{{ $totals['instalments'] }}</td>
            </tr>
        </tfoot>
    </table>
</div>

<div class="section section-break">
    <h2>Le détail, mois par mois</h2>

    <p class="lede">
        Chaque versement reçu figure avec sa date. Un mois réglé en plusieurs
        fois porte plusieurs lignes de versement, et son délai se compte
        jusqu'à la dernière.
    </p>

    <table class="grid">
        <thead>
            <tr>
                <th class="text">Mois</th>
                <th class="text">Assureur</th>
                <th class="text">Statut</th>
                <th>Facturé</th>
                <th>Encaissé</th>
                <th>Reste dû</th>
                <th class="text">Dépôt</th>
                <th class="text">Versements reçus</th>
                <th>Délai</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($declarations as $declaration)
                <tr>
                    <td class="text">{{ \App\Support\MonthLabel::short($declaration->period_month, $declaration->period_year) }}</td>
                    <td class="text">{{ $declaration->insurer->name }}</td>
                    <td class="text">
                        <span class="status status-{{ $declaration->status->value }}">
                            {{ $declaration->status->label() }}
                        </span>
                    </td>
                    <td>{{ \App\Support\Fcfa::format($declaration->amount_invoiced) }}</td>
                    <td>{{ \App\Support\Fcfa::format($declaration->amount_received) }}</td>
                    <td>{{ $declaration->amount_outstanding > 0 ? \App\Support\Fcfa::format($declaration->amount_outstanding) : '—' }}</td>
                    <td class="text">{{ $declaration->invoice_deposited_on?->format('d/m/Y') ?? '—' }}</td>
                    <td class="text">
                        @forelse ($declaration->payments as $payment)
                            <div>
                                {{ \App\Support\Fcfa::format($payment->amount) }} le
                                {{ $payment->paid_on->format('d/m/Y') }}@if ($payment->delay_days !== null) <span class="sub">({{ $payment->delay_days }} j)</span>@endif
                            </div>
                        @empty
                            <span class="sub">aucun</span>
                        @endforelse
                    </td>
                    <td class="{{ $declaration->delay_days !== null && $declaration->delay_days > $declaration->insurer->standard_delay_days ? 'late' : '' }}">
                        {{ $declaration->delay_days === null ? '—' : $declaration->delay_days.' j' }}
                        @if ($declaration->revisions_count > 1)
                            <div class="sub">{{ $declaration->revisions_count - 1 }} corr.</div>
                        @endif
                    </td>
                </tr>
            @endforeach

            @if ($declarations->isEmpty())
                <tr><td class="text" colspan="9">Aucune déclaration sur la période retenue.</td></tr>
            @endif
        </tbody>
    </table>

    <p class="note">
        Vos notes privées ne figurent pas dans ce relevé mais restent dans le
        fichier CSV et le classeur, qui sont destinés au même usage interne.
        Aucun de ces trois fichiers ne quitte l'officine par l'application.
    </p>
</div>
