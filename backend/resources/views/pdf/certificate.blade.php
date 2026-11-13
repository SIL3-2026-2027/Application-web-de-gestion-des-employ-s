@extends('pdf.layout')

@php
    $e = $contract->employee;
    $end = $contract->effectiveEndDate();
    // Emplois successivement occupés : poste du contrat puis postes des avenants
    $positions = collect([$contract->position?->title])
        ->merge($contract->amendments->map(fn ($a) => $a->position?->title))
        ->filter()
        ->unique()
        ->values();
@endphp

@section('title', 'Certificat de travail')

@section('content')
    <h1>Certificat de travail</h1>
    <div class="subtitle">&nbsp;</div>

    <p>
        Je soussigné(e), {{ $company['representative'] }}, {{ $company['representative_title'] }} de la société
        <strong>{{ $company['name'] }}</strong>, {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }},
        SIRET {{ $company['siret'] }}, certifie que :
    </p>

    <p style="text-align:center; font-size:12pt;">
        <strong>{{ $e->civility }} {{ $e->first_name }} {{ mb_strtoupper($e->last_name) }}</strong>
    </p>

    <p>
        a été {{ $e->civility === 'Mme' ? 'employée' : 'employé' }} dans notre entreprise du <strong>{{ $contract->start_date->format('d/m/Y') }}</strong>
        au <strong>{{ $end?->format('d/m/Y') }}</strong>, en contrat {{ $contract->type }},
        @if ($positions->count() > 1)
            et a occupé successivement les emplois suivants : {{ $positions->join(', ', ' puis ') }}.
        @else
            en qualité de {{ $positions->first() ?? 'salarié(e)' }}.
        @endif
    </p>

    <p>
        {{ $e->civility === 'Mme' ? 'Elle' : 'Il' }} nous quitte libre de tout engagement.
    </p>

    <p class="muted">
        Le salarié peut, s'il le souhaite, conserver le bénéfice de la portabilité de ses garanties de prévoyance
        et de frais de santé dans les conditions prévues par la loi.
    </p>

    <p>Certificat délivré pour servir et valoir ce que de droit.</p>

    <p>Fait à {{ $company['city'] }}, le {{ now()->format('d/m/Y') }}.</p>

    <table class="signatures">
        <tr>
            <td></td>
            <td>{{ $company['representative'] }}<br><span class="muted">{{ $company['representative_title'] }}</span><div class="sign-box"></div></td>
        </tr>
    </table>
@endsection
