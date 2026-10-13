@php use App\Support\Format; @endphp
@extends('pdf.layout')

@section('content')
    <h1>{{ $title }}</h1>
    <p class="muted">Reference {{ $investment->reference }}</p>

    @foreach ($paragraphs as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach

    <h2>Investment schedule</h2>
    <table class="kv">
        <tr><td class="label">Investor</td><td class="value">{{ $customer->displayName() }}</td></tr>
        <tr><td class="label">Email</td><td class="value">{{ $customer->email }}</td></tr>
        @if ($investment->isBuyback())
            <tr><td class="label">Estate</td><td class="value">{{ $investment->estate?->name }}</td></tr>
            <tr><td class="label">Plots</td><td class="value">
                @foreach ($investment->plots as $plot){{ $plot->code }} · {{ $plot->size_sqm }} sqm<br>@endforeach
            </td></tr>
        @endif
        <tr><td class="label">{{ $investment->isBuyback() ? 'Purchase price' : 'Investment amount' }}</td><td class="value">{{ Format::naira($investment->principal) }}</td></tr>
        <tr><td class="label">Tenure</td><td class="value">{{ Format::months($investment->tenure_months) }}</td></tr>
        <tr><td class="label">Return</td><td class="value">{{ Format::percent($investment->roi_percent) }}</td></tr>
        <tr><td class="label">Projected payout</td><td class="value">{{ Format::naira($investment->projected_return) }}</td></tr>
        <tr><td class="label">Maturity date</td><td class="value">{{ Format::dateLong($investment->maturity_date) }}</td></tr>
    </table>

    <h2>Terms</h2>
    <ol>
        <li>The Company shall pay the projected payout to the Investor on or after the maturity date, upon request, into the Investor’s verified payout account.</li>
        <li>The maturity date is computed from the date payment is confirmed; the schedule above is indicative until payment is received.</li>
        <li>This Agreement becomes effective only upon confirmation of full payment by the Company.</li>
        <li>Early termination by the Investor is subject to the Company’s written approval and applicable charges.</li>
        <li>This Agreement is governed by the laws of the Federal Republic of Nigeria.</li>
    </ol>

    @if ($signature)
        <div class="signature">
            <p><span class="stamp">ELECTRONICALLY SIGNED</span></p>
            <p>Signed by <strong>{{ $signature['name'] }}</strong> on {{ $signature['signed_at'] }} from IP {{ $signature['ip'] }}.</p>
            <p class="muted">Reviewed document SHA-256: {{ $signature['hash'] }}</p>
            <p>For and on behalf of {{ $company }}: <strong>Authorised signatory</strong></p>
        </div>
    @else
        <div class="signature">
            <p class="muted">Awaiting the Investor’s electronic signature.</p>
        </div>
    @endif
@endsection
