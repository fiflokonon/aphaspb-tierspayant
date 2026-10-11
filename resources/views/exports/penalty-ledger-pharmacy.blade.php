{{--
    La trace des pénalités d'une officine, mise en page pour dompdf et pour
    être jointe à une réclamation : la pénalité exigible au jour, puis, par
    assureur et par facture, le calcul et chaque événement qui y mène.

    Mêmes contraintes moteur que le relevé : ni flexbox ni grid — les colonnes
    sont des tableaux —, `position: fixed` pour ce qui se répète de page en
    page, et `page-break-inside: avoid` sur les lignes. Les libellés viennent de
    PharmacyPenaltyTraceRows ($wording), pour que le PDF et le tableur disent
    les mêmes mots.
--}}
@php
    $money = fn (?int $value): string => \App\Support\Fcfa::format($value ?? 0).' F';
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

    .invoice { margin-top: 16px; }
    .invoice h3 { margin: 0 0 2px; font-size: 9px; }
    .invoice .meta { color: #6b7878; font-size: 7.5px; }
    .invoice .calc { margin: 4px 0 0; color: #1d4a46; font-size: 8px; font-weight: bold; }
    .grid .muted { color: #8b9797; }
    .status-running { background: #f4eceb; color: #b4472e; }
    .status-closed { background: #eceeee; color: #556161; }
</style>

<div class="sheet-header">
    <div class="kicker">APhaSPB · Journal des pénalités</div>
    <div class="brand">{{ $pharmacyName }}</div>
    <div class="scope">Pénalité exigible au {{ $trace['asOf'] }} · détail par facture</div>
</div>

<div class="sheet-footer">
    <table>
        <tr>
            <td style="text-align: left;">
                Édité le {{ $generatedAt->translatedFormat('d/m/Y à H:i') }} ·
                Établi à partir des déclarations de l'officine et des
                conventions de pénalité de chaque assureur.
            </td>
            <td style="text-align: right;">Page <span class="page-number"></span></td>
        </tr>
    </table>
</div>

@if ($trace['insurers'] === [])
    <h2>Aucune pénalité à ce jour</h2>
    <p class="lede">
        Aucune facture déposée chez un assureur sous convention n'a encore
        atteint son délai de déclenchement : il n'y a rien à réclamer.
    </p>
@else
<table class="kpis">
    <tr>
        <td>
            <div class="value">{{ \App\Support\Fcfa::format($trace['due']) }} <span class="unit">F</span></div>
            <div class="label">Pénalité exigible au {{ $trace['asOf'] }}</div>
        </td>
    </tr>
</table>

<p class="note">
    Mode de calcul : la pénalité se déclenche à la fin du délai de la
    convention, compté depuis le dépôt de la facture. Chaque tranche de 30 jours
    commencée ajoute le montant facturé × le taux de la convention, même après
    un acompte. La pénalité cesse de courir le jour où la facture est soldée.
</p>

<div class="section">
    <h2>Par assureur</h2>
    <table class="grid">
        <thead>
            <tr>
                <th class="text">Assureur</th>
                <th class="text">Convention</th>
                <th>Factures</th>
                <th>Pénalité courue</th>
                <th>Exigible</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($trace['insurers'] as $insurer)
                <tr>
                    <td class="text name">{{ $insurer['name'] }}</td>
                    <td class="text">{{ $insurer['triggerDays'] }} jours · {{ $wording->percent($insurer['ratePercent']) }} / {{ $insurer['trancheDays'] }} jours</td>
                    <td>{{ count($insurer['invoices']) }}</td>
                    <td>{{ $money($insurer['accrued']) }}</td>
                    <td class="name">{{ $money($insurer['due']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="text" colspan="4">Total exigible au {{ $trace['asOf'] }}</td>
                <td>{{ $money($trace['due']) }}</td>
            </tr>
        </tfoot>
    </table>
</div>

@foreach ($trace['insurers'] as $insurer)
    <div class="insurer-page">
        <h2>{{ $insurer['name'] }}</h2>
        <p class="convention">
            Pénalité à partir de {{ $insurer['triggerDays'] }} jours après le dépôt ·
            {{ $wording->percent($insurer['ratePercent']) }} du montant facturé par
            tranche de {{ $insurer['trancheDays'] }} jours ·
            exigible : <strong>{{ $money($insurer['due']) }}</strong>
        </p>

        @foreach ($insurer['invoices'] as $invoice)
            @php
                $tone = $invoice['settlement'] !== null ? 'status-closed' : ($invoice['running'] ? 'status-running' : 'status-partial');
            @endphp
            <div class="invoice">
                <h3>Mois déclaré {{ $invoice['periodLabel'] }} · {{ $money($invoice['amountInvoiced']) }} facturés</h3>
                <div class="meta">
                    <span class="status {{ $tone }}">{{ $wording->status($invoice) }}</span> ·
                    Déposée le {{ $invoice['depositedOn'] }} · déclenchement le {{ $invoice['triggerOn'] }} ·
                    encaissé {{ $money($invoice['amountReceived']) }}
                    @if ($invoice['nextTrancheOn'] !== null) · prochaine tranche le {{ $invoice['nextTrancheOn'] }} @endif
                </div>

                <table class="grid">
                    <thead>
                        <tr>
                            <th class="text">Date</th>
                            <th class="text">Événement</th>
                            <th class="text">Détail</th>
                            <th>Pénalité</th>
                            <th>Cumul</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice['events'] as $event)
                            <tr>
                                <td class="text">{{ $event['on'] }}</td>
                                <td class="text">{{ $wording->title($event) }}</td>
                                <td class="text muted">{{ $wording->detail($event, $invoice, $insurer) }}</td>
                                <td>{{ $event['kind'] === 'tranche' ? '+ '.$money($event['amount']) : '' }}</td>
                                <td>{{ $event['cumulative'] === null ? '' : $money($event['cumulative']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="text" colspan="4">
                                Exigible au {{ $trace['asOf'] }} · {{ $wording->dueDetail($invoice) }}
                            </td>
                            <td>{{ $money($invoice['due']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endforeach
    </div>
@endforeach
@endif
