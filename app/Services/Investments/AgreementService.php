<?php

namespace App\Services\Investments;

use App\Enums\DocumentStatus;
use App\Enums\InvestmentStatus;
use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\Investment;
use App\Services\Documents\DocumentService;
use App\Services\Notifier;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Agreement generation, preview and e-signature (contract §6.7 / §7.4).
 * The signature record keeps the hash of the exact document the customer reviewed.
 */
class AgreementService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly Notifier $notifier,
    ) {}

    /** @return array{id: int, title: string, document_url: string, page_count: int|null, preview_paragraphs: list<string>, signed_at: string|null} */
    public function present(Investment $investment): array
    {
        $document = $this->agreementDocument($investment);

        return [
            'id' => $document->id,
            'title' => $investment->agreementTitle(),
            'document_url' => $this->documents->previewUrl($document),
            'page_count' => $document->page_count,
            'preview_paragraphs' => $this->paragraphs($investment),
            'signed_at' => Format::iso($investment->agreement_signed_at),
        ];
    }

    public function agreementDocument(Investment $investment): Document
    {
        $existing = $investment->documents()->where('type', $investment->agreementDocumentType())->first();

        if ($existing?->hasFile()) {
            return $existing;
        }

        return $this->documents->generate(
            $investment,
            $investment->user,
            $investment->agreementDocumentType(),
            'pdf.agreement',
            $this->viewData($investment),
            DocumentStatus::NotSigned,
            $existing,
        );
    }

    public function sign(Investment $investment, ?string $typedName, Request $request): Investment
    {
        if ($investment->agreement_signed_at !== null) {
            return $investment; // idempotent re-submit
        }

        if ($investment->status !== InvestmentStatus::PendingSignature) {
            throw ApiException::conflict('This agreement can no longer be signed.', 'invalid_state');
        }

        $reviewed = $this->agreementDocument($investment);
        $signedAt = now();

        $document = DB::transaction(function () use ($investment, $reviewed, $typedName, $request, $signedAt) {
            $investment->signatures()->create([
                'user_id' => $investment->user_id,
                'document_id' => $reviewed->id,
                'accepted' => true,
                'typed_signature_name' => $typedName,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'document_hash' => (string) $reviewed->hash,
                'signed_at' => $signedAt,
            ]);

            $investment->forceFill([
                'status' => InvestmentStatus::PendingPayment,
                'agreement_signed_at' => $signedAt,
                'reservation_expires_at' => $investment->isBuyback()
                    ? now()->addMinutes((int) config('amcom.buyback.reservation_minutes'))
                    : null,
            ])->save();

            if ($investment->isBuyback()) {
                $investment->plots()->update(['reserved_until' => $investment->reservation_expires_at]);
            }

            // Executed copy with the signature block (the reviewed hash stays on the signature record).
            return $this->documents->generate(
                $investment,
                $investment->user,
                $investment->agreementDocumentType(),
                'pdf.agreement',
                $this->viewData($investment) + [
                    'signature' => [
                        'name' => $typedName ?: $investment->user->displayName(),
                        'signed_at' => $signedAt->timezone(config('amcom.inspections.timezone'))->format('j M Y, g:i A T'),
                        'ip' => $request->ip(),
                        'hash' => $reviewed->hash,
                    ],
                ],
                DocumentStatus::Available,
                $reviewed,
            );
        });

        $this->notifier->user($investment->user, 'agreement_signed', [
            'agreement_title' => $investment->agreementTitle(),
            'reference' => $investment->reference,
            'time' => $signedAt->format('M j, Y g:i A'),
            'checkout_path' => $investment->isBuyback()
                ? '/buyback/estates/'.$investment->estate?->slug.'/checkout?step=payment&order='.$investment->id
                : '/capital-investment/invest?step=payment&order='.$investment->id,
        ], [$document->id]);

        return $investment->fresh();
    }

    /** @return list<string> */
    public function paragraphs(Investment $investment): array
    {
        $company = config('amcom.company.name');
        $customer = $investment->user->displayName();
        $date = Format::dateLong(now());

        if ($investment->isBuyback()) {
            $plots = $investment->plots->map(fn ($p) => $p->code.' ('.$p->size_sqm.' sqm)')->implode(', ');

            return [
                "This Agreement executed and signed on this day, {$date}, b/w {$company} (hereinafter called \"the Company\") of the one part",
                'AND',
                "{$customer} (hereinafter called \"the Investor\") of the other part.",
                "WHEREAS the Investor wishes to purchase plot(s) {$plots} at {$investment->estate?->name} for a total consideration of ".Format::naira($investment->principal).'.',
                "AND WHEREAS the Company undertakes to buy back the said plot(s) at the end of a {$investment->tenure_months}-month tenure for ".Format::naira($investment->projected_return).' ('.Format::percent($investment->roi_percent).' return), maturing on '.Format::dateLong($investment->maturity_date).'.',
            ];
        }

        return [
            "This Capital Investment Agreement is made on {$date} between {$company} (\"the Company\") and {$customer} (\"the Investor\").",
            'The Investor agrees to invest '.Format::naira($investment->principal)." for a fixed tenure of {$investment->tenure_months} months.",
            'The Company agrees to pay the Investor a fixed return of '.Format::percent($investment->roi_percent).', being a total payout of '.Format::naira($investment->projected_return).' on maturity ('.Format::dateLong($investment->maturity_date).').',
            'The principal and return are payable to the Investor’s nominated payout account upon maturity and a payout request.',
        ];
    }

    private function viewData(Investment $investment): array
    {
        $investment->loadMissing(['user.profile', 'estate', 'plots']);

        return [
            'investment' => $investment,
            'title' => $investment->agreementTitle(),
            'company' => config('amcom.company.name'),
            'customer' => $investment->user,
            'paragraphs' => $this->paragraphs($investment),
            'signature' => null,
        ];
    }
}
