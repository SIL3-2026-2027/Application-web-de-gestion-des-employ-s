@extends('pdf.layout')

@php
    $e = $contract->employee;
    $parent = $contract->contract;
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ').' €';
@endphp

@section('title', 'Avenant '.$contract->reference)

@section('content')
    <h1>Avenant au contrat de travail</h1>
    <div class="subtitle">Avenant {{ $contract->reference }} au contrat {{ $parent->reference }}</div>

    <p>
        Entre <strong>{{ $company['name'] }}</strong>, représentée par {{ $company['representative'] }}, {{ $company['representative_title'] }},
        et <strong>{{ $e->civility }} {{ $e->first_name }} {{ mb_strtoupper($e->last_name) }}</strong>,
        {{ $e->civility === 'Mme' ? 'liée' : 'lié' }} par un contrat {{ $parent->type }} ayant pris effet le {{ $parent->start_date->format('d/m/Y') }},
        il est convenu de modifier le contrat comme suit, à compter du <strong>{{ $contract->start_date->format('d/m/Y') }}</strong> :
    </p>

    <table class="terms">
        @if ($contract->position)
            <tr><td>Nouveau poste</td><td>{{ $contract->position->title }}</td></tr>
        @endif
        @if ($contract->department)
            <tr><td>Nouveau département</td><td>{{ $contract->department->name }}</td></tr>
        @endif
        @if ($contract->weekly_hours)
            <tr><td>Nouvelle durée hebdomadaire</td><td>{{ rtrim(rtrim(number_format($contract->weekly_hours, 1, ',', ''), '0'), ',') }} heures</td></tr>
        @endif
        @if ($contract->base_salary)
            <tr><td>Nouvelle rémunération brute annuelle</td><td>{{ $money($contract->base_salary) }}</td></tr>
        @endif
        @if ($contract->end_date)
            <tr><td>Nouvelle date de fin</td><td>{{ $contract->end_date->format('d/m/Y') }}</td></tr>
        @endif
    </table>

    @if ($contract->description)
        <p>{!! nl2br(e($contract->description)) !!}</p>
    @endif

    <p>Toutes les autres clauses du contrat de travail demeurent inchangées.</p>

    <p>Fait à {{ $company['city'] }}, le {{ now()->format('d/m/Y') }}, en deux exemplaires originaux.</p>

    <table class="signatures">
        <tr>
            <td>Pour l'employeur<br><span class="muted">{{ $company['representative'] }}</span><div class="sign-box"></div></td>
            <td>Le salarié<br><span class="muted">« Lu et approuvé »</span><div class="sign-box"></div></td>
        </tr>
    </table>
@endsection
