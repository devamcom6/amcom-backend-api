<?php

namespace App\Enums;

enum DocumentType: string
{
    use HasValues;

    case PaymentReceipt = 'payment_receipt';
    case InvestmentContract = 'investment_contract';
    case InvestmentCertificate = 'investment_certificate';
    case BuybackAgreement = 'buyback_agreement';
    case PostDatedCheque = 'post_dated_cheque';
    case TitleDeed = 'title_deed';
    case SurveyPlan = 'survey_plan';
    case DeedOfConveyance = 'deed_of_conveyance';
    case ProofOfOwnership = 'proof_of_ownership';
    case LeaseAgreement = 'lease_agreement';
    case TenancyAgreement = 'tenancy_agreement';
    case PropertyPhotos = 'property_photos';
    case TitleDocuments = 'title_documents';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PaymentReceipt => 'Payment receipt',
            self::InvestmentContract => 'Investment Contract',
            self::InvestmentCertificate => 'Investment certificate',
            self::BuybackAgreement => 'Buy-back agreement',
            self::PostDatedCheque => 'Post-dated cheque',
            self::TitleDeed => 'Title deed',
            self::SurveyPlan => 'Survey plan',
            self::DeedOfConveyance => 'Deed of conveyance',
            self::ProofOfOwnership => 'Proof of ownership',
            self::LeaseAgreement => 'Lease agreement',
            self::TenancyAgreement => 'Tenancy agreement',
            self::PropertyPhotos => 'Property photos',
            self::TitleDocuments => 'Title documents',
            self::Other => 'Document',
        };
    }
}
