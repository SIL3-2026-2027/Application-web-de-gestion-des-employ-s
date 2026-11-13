@extends('pdf.layout')

@php
    $e = $employee;
    $isFemale = $e->civility === 'Mme';
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ').' '.config('hr.currency');
    $position = \App\Models\Position::find($terms['position_id'])?->title ?? $e->position?->title;
@endphp

@section('title', 'Attestation de salaire')

@section('content')
    <h1>Attestation de rémunération</h1>
    <div class="subtitle">&nbsp;</div>

    <p>
        Je soussigné(e), {{ $company['representative'] }}, {{ $company['representative_title'] }} de la société
        <strong>{{ $company['name'] }}</strong>, {{ $company['address'] }}, {{ $company['postal_box'] }} {{ $company['city'] }} ({{ $company['country'] }}),
        IFU {{ $company['ifu'] }}, RCCM {{ $company['rccm'] }}, atteste que :
    </p>

    <p style="text-align:center; font-size:12pt;">
        <strong>{{ $e->civility }} {{ $e->first_name }} {{ mb_strtoupper($e->last_name) }}</strong>
    </p>

    <p>
        {{ $isFemale ? 'employée' : 'employé' }} depuis le {{ $e->hire_date->format('d/m/Y') }} en qualité de {{ $position ?? 'salarié(e)' }},
        en contrat {{ $contract->type }}, perçoit à ce jour la rémunération suivante :
    </p>

    <table class="terms">
        <tr><td>Salaire brut mensuel</td><td>{{ $money($terms['base_salary']) }}</td></tr>
        <tr><td>Soit, par an (12 mois)</td><td>{{ $money($terms['base_salary'] * 12) }} brut</td></tr>
        @if ($terms['weekly_hours'])
            <tr><td>Durée hebdomadaire de travail</td><td>{{ rtrim(rtrim(number_format($terms['weekly_hours'], 1, ',', ''), '0'), ',') }} heures</td></tr>
        @endif
    </table>

    <p class="muted">Montants hors primes et éléments variables de rémunération.</p>

    <p>Attestation délivrée à la demande de l'intéressé(e), pour servir et valoir ce que de droit.</p>

    <p>Fait à {{ $company['city'] }}, le {{ now()->format('d/m/Y') }}.</p>

    <table class="signatures">
        <tr>
            <td></td>
            <td>{{ $company['representative'] }}<br><span class="muted">{{ $company['representative_title'] }}</span><div class="sign-box"></div></td>
        </tr>
    </table>
@endsection
