<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 2.2cm 2cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #222; line-height: 1.5; }
        .company { font-size: 9pt; color: #555; margin-bottom: 1.2cm; }
        .company strong { font-size: 11pt; color: #1f4e79; }
        h1 { font-size: 16pt; color: #1f4e79; text-align: center; margin: 0 0 .2cm; }
        .subtitle { text-align: center; color: #666; margin-bottom: 1cm; }
        h2 { page-break-after: avoid; font-size: 11pt; color: #1f4e79; border-bottom: 1px solid #c9d6e3; padding-bottom: 2px; margin-top: .7cm; }
        table.terms { page-break-inside: avoid; width: 100%; border-collapse: collapse; margin: .2cm 0; }
        table.terms td { padding: 4px 6px; border-bottom: 1px solid #eee; vertical-align: top; }
        table.terms td:first-child { width: 38%; color: #555; }
        .signatures { width: 100%; margin-top: 1.5cm; }
        .signatures td { width: 50%; vertical-align: top; padding-right: 1cm; }
        .sign-box { height: 2.2cm; }
        .muted { color: #777; font-size: 9pt; }
        .footer { position: fixed; bottom: -1.2cm; left: 0; right: 0; text-align: center; font-size: 8pt; color: #999; }
    </style>
</head>
<body>
    <div class="company">
        <strong>{{ $company['name'] }}</strong><br>
        {{ $company['address'] }}, {{ $company['postal_box'] }} {{ $company['city'] }} ({{ $company['country'] }})<br>
        IFU {{ $company['ifu'] }} — RCCM {{ $company['rccm'] }} — CNSS employeur n° {{ $company['cnss'] }}
    </div>

    @yield('content')

    <div class="footer">{{ $company['name'] }} — document généré le {{ now()->format('d/m/Y') }}</div>
</body>
</html>
