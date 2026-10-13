@php use App\Support\Format; @endphp
@extends('pdf.layout')

@section('content')
    <div style="text-align: center; margin-top: 40px;">
        <h1 style="font-size: 26px;">Investment Certificate</h1>
        <p class="muted">Certificate No. {{ $investment->reference }}</p>

        <p style="margin-top: 30px; font-size: 13px;">This certifies that</p>
        <p style="font-size: 20px; font-weight: bold;">{{ $investment->user->displayName() }}</p>
        <p style="font-size: 13px;">holds a {{ $investment->planName() }} capital investment with {{ config('amcom.company.name') }}</p>
    </div>

    <table class="kv" style="margin-top: 30px;">
        <tr><td class="label">Principal</td><td class="value">{{ Format::naira($investment->principal) }}</td></tr>
        <tr><td class="label">Fixed return</td><td class="value">{{ Format::percent($investment->roi_percent) }}</td></tr>
        <tr><td class="label">Total payout at maturity</td><td class="value">{{ Format::naira($investment->projected_return) }}</td></tr>
        <tr><td class="label">Start date</td><td class="value">{{ Format::dateLong($investment->started_at) }}</td></tr>
        <tr><td class="label">Maturity date</td><td class="value">{{ Format::dateLong($investment->maturity_date) }}</td></tr>
    </table>

    <div class="signature" style="text-align: center;">
        <p>For and on behalf of {{ config('amcom.company.name') }}</p>
        <p><strong>Authorised signatory</strong></p>
    </div>
@endsection
