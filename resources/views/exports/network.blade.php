{{--
    Le rapport réseau, mis en page pour dompdf.

    Contraintes du moteur, qui expliquent la structure : ni flexbox ni grid, donc
    les colonnes sont des tableaux ; `position: fixed` est la seule chose qui se
    répète sur chaque page, d'où l'en-tête et le pied hors flux ; et une ligne de
    tableau ne se coupe proprement qu'avec `page-break-inside: avoid`.
--}}
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
</style>

<div class="sheet-header">
    <div class="kicker">APhaSPB · Observatoire du tiers-payant</div>
    <div class="brand">Rapport réseau</div>
    <div class="scope">
        {{ $periodLabel }}
        @if ($city) · officines de {{ $city }} @else · toutes les officines @endif
    </div>
</div>

<div class="sheet-footer">
    <table>
        <tr>
            <td style="text-align: left;">
                Édité le {{ $generatedAt->translatedFormat('d/m/Y à H:i') }} ·
                Chiffres agrégés, aucune officine n'y est identifiable.
            </td>
            <td style="text-align: right;">
                Page <span class="page-number"></span>
            </td>
        </tr>
    </table>
</div>

<h2>Ce que le réseau a constaté</h2>

<p class="lede">
    {{ $summary['declarations'] }} déclarations déposées par
    {{ $summary['declaringPharmacies'] }} officines sur la période.
    Le délai moyen se compte du dépôt de la facture au dernier versement reçu.
</p>

<table class="kpis">
    <tr>
        <td>
            <div class="value">
                {{ $summary['averageDelayDays'] === null ? '—' : number_format($summary['averageDelayDays'], 1, ',', ' ') }}<span class="unit"> j</span>
            </div>
            <div class="label">Délai moyen</div>
        </td>
        <td>
            <div class="value">
                {{ $summary['weightedDelayDays'] === null ? '—' : number_format($summary['weightedDelayDays'], 1, ',', ' ') }}<span class="unit"> j</span>
            </div>
            <div class="label">Pondéré par les montants</div>
        </td>
        <td>
            <div class="value">
                {{ $summary['withinThresholdShare'] === null ? '—' : number_format($summary['withinThresholdShare'], 1, ',', ' ') }}<span class="unit"> %</span>
            </div>
            <div class="label">Déclarations dans les délais</div>
        </td>
        <td>
            <div class="value">
                {{ $summary['rejectionRate'] === null ? '—' : number_format($summary['rejectionRate'], 1, ',', ' ') }}<span class="unit"> %</span>
            </div>
            <div class="label">Taux de rejet</div>
        </td>
    </tr>
</table>

<div class="section">
    <h2>Assureur par assureur</h2>

    <p class="lede">
        Deux mesures de ponctualité, volontairement distinctes. « Déclarations »
        compte les mois réglés dans le délai standard de l'assureur ;
        « argent » compte la part des sommes facturées effectivement arrivée
        dans ce délai, versement par versement. L'écart entre les deux est ce
        que coûte un règlement fractionné.
    </p>

    <table class="grid">
        <thead>
            <tr>
                <th class="text">Assureur</th>
                <th>Officines</th>
                <th>Décl.</th>
                <th>Délai moyen</th>
                <th>1<sup>er</sup> versement</th>
                <th>Décl. à l'heure</th>
                <th>Argent à l'heure</th>
                <th>Versements / décl.</th>
                <th>Facturé</th>
                <th>Encaissé</th>
                <th>Reste dû</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @php($indicators = $row['indicators'])
                <tr>
                    <td class="text">
                        <div class="name">{{ $row['name'] }}</div>
                        <div class="sub">standard {{ $indicators->standardDelayDays }} j</div>
                    </td>
                    <td>{{ $indicators->declaringPharmacies }}</td>
                    <td>{{ $indicators->declarations }}</td>
                    <td class="{{ $indicators->averageDelayDays !== null && $indicators->averageDelayDays > $indicators->standardDelayDays ? 'late' : '' }}">
                        {{ $indicators->averageDelayDays === null ? '—' : number_format($indicators->averageDelayDays, 1, ',', ' ').' j' }}
                    </td>
                    <td>
                        {{ $indicators->averageFirstInstalmentDelayDays === null ? '—' : number_format($indicators->averageFirstInstalmentDelayDays, 1, ',', ' ').' j' }}
                    </td>
                    <td>
                        {{ $indicators->withinThresholdShare === null ? '—' : number_format($indicators->withinThresholdShare, 1, ',', ' ').' %' }}
                    </td>
                    <td>
                        {{ $indicators->recoveredWithinDelayShare === null ? '—' : number_format($indicators->recoveredWithinDelayShare, 1, ',', ' ').' %' }}
                    </td>
                    <td>
                        {{ $indicators->instalmentsPerDeclaration === null ? '—' : number_format($indicators->instalmentsPerDeclaration, 1, ',', ' ') }}
                        <div class="sub">
                            {{ $indicators->multiInstalmentShare === null ? '—' : number_format($indicators->multiInstalmentShare, 0, ',', ' ').' % fractionnés' }}
                        </div>
                    </td>
                    <td>{{ $row['amounts'] === null ? '—' : \App\Support\Fcfa::format($row['amounts']->invoiced) }}</td>
                    <td>{{ $row['amounts'] === null ? '—' : \App\Support\Fcfa::format($row['amounts']->received) }}</td>
                    <td>{{ $row['amounts'] === null ? '—' : \App\Support\Fcfa::format($row['amounts']->outstanding) }}</td>
                </tr>
            @endforeach

            @foreach ($withheld as $entry)
                <tr class="withheld">
                    <td class="text">{{ $entry['name'] }}</td>
                    <td colspan="10">
                        {{ $entry['declaringPharmacies'] }} officine{{ $entry['declaringPharmacies'] > 1 ? 's' : '' }}
                        déclarante{{ $entry['declaringPharmacies'] > 1 ? 's' : '' }} —
                        chiffres retenus, affichage à partir de {{ $anonymityThreshold }}
                    </td>
                </tr>
            @endforeach

            @if (count($rows) === 0 && count($withheld) === 0)
                <tr>
                    <td class="text" colspan="11">
                        Aucune déclaration sur la période retenue.
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    @if (count($withheld) > 0)
        <p class="note">
            Les assureurs déclarés par moins de {{ $anonymityThreshold }} officines
            figurent sans chiffres. En deçà de ce seuil, une moyenne réseau
            redonnerait les données d'une officine identifiable — la ligne est
            conservée pour que son absence ne se lise pas comme une absence de
            déclarations.
        </p>
    @endif
</div>
