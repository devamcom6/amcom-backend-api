@php use App\Support\Format; @endphp
@extends('pdf.layout')

@section('content')
    <h1>Payment receipt</h1>
    <p class="muted">Receipt for payment {{ $transaction->reference }}</p>

    <table class="kv">
        <tr><td class="label">Received from</td><td class="value">{{ $user->displayName() }}</td></tr>
        <tr><td class="label">Email</td><td class="value">{{ $user->email }}</td></tr>
        <tr><td class="label">Description</td><td class="value">{{ $transaction->description }}</td></tr>
        @if ($subjectReference)
            <tr><td class="label">Reference</td><td class="value">{{ $subjectReference }}</td></tr>
        @endif
        <tr><td class="label">Amount paid</td><td class="value">{{ Format::naira($transaction->amount) }}</td></tr>
        <tr><td class="label">Payment method</td><td class="value">{{ str_replace('_', ' ', ucfirst($transaction->channel ?? $transaction->method->value)) }}</td></tr>
        <tr><td class="label">Payment date</td><td class="value">{{ Format::date($transaction->paid_at) }}</td></tr>
        <tr><td class="label">Provider reference</td><td class="value">{{ $transaction->provider_reference ?? $transaction->reference }}</td></tr>
    </table>

    <p style="margin-top: 24px"><span class="stamp">PAID</span></p>
    <p class="muted">Payment processed securely by Paystack. Keep this receipt for your records.</p>
@endsection
