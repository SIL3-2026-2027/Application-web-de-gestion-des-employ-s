@extends('pdf.layout')

@php
    $e = $contract->employee;
    $labels = ['CDI' => 'à durée indéterminée', 'CDD' => 'à durée déterminée', 'stage' => 'de stage (convention)', 'alternance' => "d'apprentissage / de professionnalisation", 'interim' => 'de mission temporaire', 'freelance' => 'de prestation'];
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ').' €';
@endphp

@section('title', 'Contrat '.$contract->reference)

@section('content')
    <h1>Contrat de travail {{ $labels[$contract->type] ?? '' }}</h1>
    <div class="subtitle">Référence {{ $contract->reference }}</div>

    <p>Entre les soussignés :</p>
    <p>
        <strong>{{ $company['name'] }}</strong>, dont le siège est situé {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }},
        immatriculée sous le numéro SIRET {{ $company['siret'] }}, représentée par {{ $company['representative'] }}, {{ $company['representative_title'] }},
        ci-après « l'employeur »,
    </p>
    <p>
        et <strong>{{ $e->civility }} {{ $e->first_name }} {{ mb_strtoupper($e->last_name) }}</strong>
        @if ($e->birth_date) {{ $e->civility === 'Mme' ? 'née' : 'né' }} le {{ $e->birth_date->format('d/m/Y') }}@if ($e->birth_place) à {{ $e->birth_place }}@endif,@endif
        @if ($e->address) demeurant {{ $e->address }}, {{ $e->postal_code }} {{ $e->city }},@endif
        ci-après {{ $e->civility === 'Mme' ? '« la salariée »' : '« le salarié »' }},
    </p>
    <p>il a été convenu ce qui suit.</p>

    <h2>Article 1 — Engagement</h2>
    <table class="terms">
        <tr><td>Poste</td><td>{{ $contract->position?->title ?? 'À préciser' }}</td></tr>
        <tr><td>Département</td><td>{{ $contract->department?->name ?? '—' }}</td></tr>
        <tr><td>Lieu de travail</td><td>{{ $e->site?->name ?? $company['city'] }}</td></tr>
        <tr><td>Date de début</td><td>{{ $contract->start_date->format('d/m/Y') }}</td></tr>
        @if ($contract->end_date)
            <tr><td>Date de fin</td><td>{{ $contract->end_date->format('d/m/Y') }}</td></tr>
        @endif
        @if ($contract->description)
            <tr><td>Motif / missions</td><td>{!! nl2br(e($contract->description)) !!}</td></tr>
        @endif
    </table>

    @if ($contract->trial_end_date)
        <h2>Article 2 — Période d'essai</h2>
        <p>Le présent contrat comporte une période d'essai prenant fin le {{ $contract->trial_end_date->format('d/m/Y') }}, au cours de laquelle chacune des parties peut y mettre fin dans les conditions légales.</p>
    @endif

    <h2>Article {{ $contract->trial_end_date ? 3 : 2 }} — Durée du travail et rémunération</h2>
    <table class="terms">
        <tr><td>Durée hebdomadaire</td><td>{{ rtrim(rtrim(number_format($contract->weekly_hours, 1, ',', ''), '0'), ',') }} heures</td></tr>
        <tr><td>Rémunération brute annuelle</td><td>{{ $money($contract->base_salary) }}, versée en 12 mensualités de {{ $money($contract->base_salary / 12) }}</td></tr>
        @if ($contract->collective_agreement)
            <tr><td>Convention collective</td><td>{{ $contract->collective_agreement }}</td></tr>
        @endif
    </table>

    <p class="muted">Les autres conditions d'emploi (congés, prévoyance, règlement intérieur) sont régies par les dispositions légales et conventionnelles en vigueur.</p>

    <p>Fait à {{ $company['city'] }}, le {{ now()->format('d/m/Y') }}, en deux exemplaires originaux.</p>

    <table class="signatures">
        <tr>
            <td>Pour l'employeur<br><span class="muted">{{ $company['representative'] }}</span><div class="sign-box"></div></td>
            <td>Le salarié<br><span class="muted">« Lu et approuvé »</span><div class="sign-box"></div></td>
        </tr>
    </table>
@endsection
