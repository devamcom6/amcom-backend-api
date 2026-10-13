<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 48px 56px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1f2933; line-height: 1.55; }
        h1 { font-size: 20px; color: #4a1d0c; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 22px 0 8px; color: #2e6e5e; text-transform: uppercase; letter-spacing: .5px; }
        .muted { color: #6b7280; }
        .brand { border-bottom: 2px solid #2e6e5e; padding-bottom: 10px; margin-bottom: 18px; }
        .brand strong { font-size: 16px; color: #2e6e5e; }
        table.kv { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.kv td { padding: 6px 0; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.kv td.label { color: #6b7280; width: 45%; }
        table.kv td.value { text-align: right; font-weight: bold; }
        .signature { margin-top: 28px; padding: 12px; border: 1px solid #d1d5db; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 9px; color: #9ca3af; text-align: center; }
        .stamp { display: inline-block; padding: 4px 10px; border: 1px solid #2e6e5e; color: #2e6e5e; font-weight: bold; }
    </style>
</head>
<body>
    <div class="brand">
        <strong>{{ config('amcom.company.brand') }}</strong>
        <span class="muted"> · {{ config('amcom.company.name') }}</span>
    </div>

    @yield('content')

    <div class="footer">
        {{ config('amcom.company.name') }} · Generated {{ now()->format('j M Y, H:i') }} UTC · This document is confidential.
    </div>
</body>
</html>
