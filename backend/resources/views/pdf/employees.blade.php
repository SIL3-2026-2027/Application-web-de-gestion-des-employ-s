<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Liste des employés</title>
    <style>
        @page { margin: 1.2cm 1cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #222; }
        h1 { font-size: 13pt; color: #1f4e79; margin: 0 0 2px; }
        .meta { color: #777; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1f4e79; color: #fff; text-align: left; padding: 4px; font-weight: bold; }
        td { padding: 3px 4px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background: #f5f9fc; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .footer { position: fixed; bottom: -0.7cm; left: 0; right: 0; text-align: center; font-size: 7pt; color: #999; }
    </style>
</head>
<body>
    <h1>Liste des employés — {{ $company['name'] }}</h1>
    <div class="meta">{{ $employees->count() }} employé(s) · édité le {{ now()->format('d/m/Y à H:i') }}</div>

    <table>
        <thead>
            <tr>
                <th>Matricule</th><th>Nom</th><th>Poste</th><th>Département</th><th>Site</th>
                <th>Manager</th><th>Embauche</th><th>Contrat</th><th>Statut</th><th>E-mail</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($employees as $e)
                <tr>
                    <td>{{ $e->matricule }}</td>
                    <td>{{ mb_strtoupper($e->last_name) }} {{ $e->first_name }}</td>
                    <td>{{ $e->position?->title }}</td>
                    <td>{{ $e->department?->name }}</td>
                    <td>{{ $e->site?->name }}</td>
                    <td>{{ $e->manager?->full_name }}</td>
                    <td>{{ $e->hire_date?->format('d/m/Y') }}</td>
                    <td>
                        {{ $e->currentContract?->type }}
                        @if ($end = $e->currentContract?->effectiveEndDate()) → {{ $end->format('d/m/Y') }} @endif
                    </td>
                    <td>{{ $status[$e->status] ?? $e->status }}</td>
                    <td>{{ $e->work_email }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">{{ $company['name'] }} — document confidentiel</div>
</body>
</html>
