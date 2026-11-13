@extends('pdf.layout')

@php
    $e = $employee;
    $isFemale = $e->civility === 'Mme';
    $position = $terms ? \App\Models\Position::find($terms['position_id'])?->title : null;
@endphp

@section('title', 'Attestation de travail')

@section('content')
    <h1>Attestation de travail</h1>
    <div class="subtitle">&nbsp;</div>

    <p>
        Je soussigné(e), {{ $company['representative'] }}, {{ $company['representative_title'] }} de la société
        <strong>{{ $company['name'] }}</strong>, {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }},
        SIRET {{ $company['siret'] }}, atteste que :
    </p>

    <p style="text-align:center; font-size:12pt;">
        <strong>{{ $e->civility }} {{ $e->first_name }} {{ mb_strtoupper($e->last_name) }}</strong>
        @if ($e->birth_date)<br><span class="muted">{{ $isFemale ? 'née' : 'né' }} le {{ $e->birth_date->format('d/m/Y') }}</span>@endif
    </p>

    @php
        $details = '';
        if ($contract) {
            $details = ", dans le cadre d'un contrat {$contract->type}";
            if ($end = $contract->effectiveEndDate()) {
                $details .= ' prenant fin le '.$end->format('d/m/Y');
            }
            if ($terms['weekly_hours']) {
                $details .= ', à raison de '.rtrim(rtrim(number_format($terms['weekly_hours'], 1, ',', ''), '0'), ',').' heures par semaine';
            }
        }
    @endphp
    <p>
        est {{ $isFemale ? 'employée' : 'employé' }} au sein de notre entreprise depuis le <strong>{{ $e->hire_date->format('d/m/Y') }}</strong>,
        en qualité de <strong>{{ $position ?? $e->position?->title ?? 'salarié(e)' }}</strong>{{ $details }}.
    </p>

    <p>{{ $isFemale ? 'Elle' : 'Il' }} fait toujours partie de nos effectifs à la date de la présente attestation.</p>

    <p>Attestation délivrée à la demande de l'intéressé(e), pour servir et valoir ce que de droit.</p>

    <p>Fait à {{ $company['city'] }}, le {{ now()->format('d/m/Y') }}.</p>

    <table class="signatures">
        <tr>
            <td></td>
            <td>{{ $company['representative'] }}<br><span class="muted">{{ $company['representative_title'] }}</span><div class="sign-box"></div></td>
        </tr>
    </table>
@endsection
