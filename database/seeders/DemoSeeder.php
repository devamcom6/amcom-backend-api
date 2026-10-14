<?php

namespace Database\Seeders;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\HoldingStatus;
use App\Enums\HoldingType;
use App\Enums\InvestmentStatus;
use App\Enums\KycStatus;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Enums\OnboardingStep;
use App\Enums\PayableType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\Product;
use App\Enums\PropertyType;
use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Models\Document;
use App\Models\Estate;
use App\Models\Investment;
use App\Models\Plot;
use App\Models\Property;
use App\Models\PropertyHolding;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\UserEvent;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Development/demo data mirroring the frontend mock accounts (handoff §1).
 * Never runs in production. Password for every demo account: SEED_PASSWORD (default Password123!).
 * Set SEED_UPLOAD_IMAGES=true with Cloudinary credentials to upload docs/images as real media
 * (or run `php artisan db:seed --class=DemoImageSeeder` later).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make((string) config('amcom.seed.password'));

        // ------------------------------------------------------------------ accounts
        $this->account('superadmin@amcom.test', 'Sade', 'Adewale', 'super_admin', $password);
        $admin = $this->account('admin@amcom.test', 'Tunde', 'Bakare', 'admin', $password);
        $user = $this->account('user@amcom.test', 'Adaeze', 'Okonkwo', 'user', $password, onboarded: true);
        $this->account('new@amcom.test', 'Ngozi', 'Eze', 'user', $password);

        User::factory()->count(24)->create()->each(function (User $u) {
            $u->profile()->save(new UserProfile(['onboarding_step' => OnboardingStep::Completed->value]));
        });
        User::factory()->count(3)->suspended()->create();

        // ------------------------------------------------------------------ estates
        $greenLush = $this->estate('green-lush-estate', 'Green Lush Estate', 'direct', 'Abara Etche', 'Etche', 'Rivers State', 4.9917, 7.0544, 'G', 20, 2_000_000, 465);
        $deVilla = $this->estate('estate-de-villa', 'Estate De Villa', 'direct', 'Rumuodara', 'Port Harcourt', 'Rivers State', 4.8396, 7.0422, 'D', 16, 2_500_000, 600);
        $this->estate('riverside-gardens', 'Riverside Gardens', 'interest', 'Igwuruta', 'Ikwerre', 'Rivers State', 4.9572, 7.0094, 'R', 12, 3_000_000, 500);

        // ------------------------------------------------------------------ marketplace
        $cities = [['Lekki', 'Lagos'], ['Ikoyi', 'Lagos'], ['Victoria Island', 'Lagos'], ['Ikeja', 'Lagos'], ['Ajah', 'Lagos'], ['Port Harcourt', 'Rivers']];
        foreach (range(1, 12) as $i) {
            [$city, $state] = $cities[$i % count($cities)];
            $type = [ListingType::Sale, ListingType::Rent, ListingType::Lease][$i % 3];
            $propertyType = [PropertyType::Apartment, PropertyType::Duplex, PropertyType::House, PropertyType::Terrace][$i % 4];
            $bedrooms = 1 + $i % 5;
            $title = "{$bedrooms} Bed ".ucfirst($propertyType->value).", {$city}";
            $property = Property::factory()->create([
                'title' => $title,
                'slug' => Str::slug($title).'-'.$i,
                'address' => fake()->streetAddress().', '.$city,
                'bedrooms' => $bedrooms,
                'bathrooms' => max(1, $bedrooms - 1),
                'city' => $city,
                'state' => $state,
                'listing_type' => $type,
                'property_type' => $propertyType,
                'price' => $type === ListingType::Sale ? (20 + $i * 15) * 1_000_000 : (2 + $i) * 1_000_000,
                'is_featured' => $i <= 6,
                'features' => ['24/7 Security', 'Fitted kitchen', 'Ample parking'],
            ]);
        }

        // ------------------------------------------------------------------ demo investor portfolio
        $greenPlot = Plot::query()->where('estate_id', $greenLush->id)->where('code', 'G092')->first()
            ?? Plot::query()->where('estate_id', $greenLush->id)->orderBy('id')->first();
        $this->position($user, Product::Buyback, 5_000_000, 24, 42, now()->subMonths(3), $greenLush, [$greenPlot]);
        $this->position($user, Product::CapitalInvestment, 8_500_000, 12, 27, now()->subMonths(8));
        $this->position($user, Product::CapitalInvestment, 2_000_000, 6, 18, now()->subMonths(7), status: InvestmentStatus::Matured);

        $deVillaPlot = Plot::query()->where('estate_id', $deVilla->id)->orderBy('id')->first();
        $this->position($user, Product::Buyback, $deVillaPlot->price, 12, 18, now()->subMonths(1), $deVilla, [$deVillaPlot]);

        // ------------------------------------------------------------------ owner listings
        $published = Property::factory()->create([
            'user_id' => $user->id, 'title' => 'Amara Heights, Unit 3C', 'city' => 'Port Harcourt', 'state' => 'Rivers',
            'address' => '9 Amara Close, Trans-Amadi, Port Harcourt', 'price' => 45_000_000, 'bedrooms' => 2, 'bathrooms' => 2,
            'fee_paid_at' => now()->subDays(20), 'submitted_at' => now()->subDays(20), 'published_at' => now()->subDays(12),
            'amenities' => ['parking', 'security', 'cctv'],
        ]);
        $published->documents()->create(['user_id' => $user->id, 'type' => DocumentType::TitleDocuments, 'name' => 'Title Documents', 'status' => DocumentStatus::PendingReview, 'disk' => 'documents']);

        $pending = Property::factory()->status(ListingStatus::PendingVerification)->create([
            'user_id' => $user->id, 'title' => '4 Bed Duplex, Eti-Osa', 'city' => 'Eti-Osa', 'state' => 'Lagos',
            'property_type' => PropertyType::Duplex, 'price' => 180_000_000, 'bedrooms' => 4,
            'fee_paid_at' => now()->subDays(2), 'submitted_at' => now()->subDays(2),
        ]);

        // ------------------------------------------------------------------ holdings
        $leased = Property::factory()->create(['title' => 'Oakwood Residences, Apt 4B', 'address' => 'Trans-Amadi, Port Harcourt', 'city' => 'Port Harcourt', 'state' => 'Rivers', 'listing_type' => ListingType::Lease, 'status' => ListingStatus::Rented]);
        $lease = PropertyHolding::create([
            'user_id' => $user->id, 'property_id' => $leased->id, 'holding_type' => HoldingType::Lease, 'status' => HoldingStatus::Active,
            'acquired_on' => now()->subYears(3)->toDateString(), 'rate' => 1_800_000, 'rate_period' => 'year',
            'period_start' => now()->subMonths(11)->toDateString(), 'period_end' => now()->addMonth()->toDateString(),
            'renewal_date' => now()->addDays(2)->toDateString(), 'next_payment_date' => now()->addMonth()->addDay()->toDateString(), 'term_label' => '2 Years',
        ]);
        $lease->payments()->create(['date' => now()->subMonths(11)->toDateString(), 'description' => 'Renewal', 'amount' => 1_800_000, 'status' => 'paid']);
        $lease->maintenanceRequests()->create(['user_id' => $user->id, 'title' => 'AC not cooling in master bedroom', 'status' => 'pending']);
        $lease->documents()->create(['user_id' => $user->id, 'type' => DocumentType::LeaseAgreement, 'name' => 'Lease agreement', 'status' => DocumentStatus::PendingReview, 'disk' => 'documents']);

        $rented = Property::factory()->create(['title' => 'Palm View Flat 2', 'city' => 'Lekki', 'state' => 'Lagos', 'listing_type' => ListingType::Rent, 'status' => ListingStatus::Rented]);
        PropertyHolding::create([
            'user_id' => $user->id, 'property_id' => $rented->id, 'holding_type' => HoldingType::Rent, 'status' => HoldingStatus::Active,
            'rate' => 250_000, 'rate_period' => 'month', 'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(), 'renewal_date' => now()->endOfMonth()->subDays(7)->toDateString(),
            'next_payment_date' => now()->startOfMonth()->addMonth()->toDateString(), 'term_label' => 'Monthly',
        ]);

        $bought = Property::factory()->create(['title' => 'Cedar Court Terrace 7', 'city' => 'Ikeja', 'state' => 'Lagos', 'status' => ListingStatus::Sold, 'property_type' => PropertyType::Terrace]);
        PropertyHolding::create([
            'user_id' => $user->id, 'property_id' => $bought->id, 'holding_type' => HoldingType::OutrightPurchase, 'status' => HoldingStatus::Active,
            'purchase_price' => 95_000_000, 'acquired_on' => now()->subYears(2)->toDateString(), 'title_document' => 'Certificate of Occupancy', 'ownership_status' => 'Verified owner',
        ]);

        // ------------------------------------------------------------------ leads & requests
        $kinds = [RequestKind::Interest, RequestKind::Application, RequestKind::Inspection, RequestKind::Contact, RequestKind::Support];
        foreach (range(1, 15) as $i) {
            $kind = $kinds[$i % count($kinds)];
            $request = ServiceRequest::create([
                'kind' => $kind, 'reference' => Reference::temporary(), 'name' => fake()->name(), 'email' => fake()->safeEmail(),
                'phone' => '+23480'.fake()->numerify('########'), 'subject_line' => ucfirst($kind->value).' request #'.$i,
                'message' => fake()->sentence(), 'status' => [RequestStatus::New, RequestStatus::InProgress, RequestStatus::Resolved][$i % 3],
                'scheduled_for' => $kind === RequestKind::Inspection ? now()->addDays($i % 7 + 1)->setTime(10, 0) : null,
                'assigned_to' => $i % 4 === 0 ? $admin->id : null,
            ]);
            $request->forceFill(['reference' => Reference::short(['interest' => 'INT', 'application' => 'APP', 'inspection' => 'INS', 'contact' => 'MSG', 'support' => 'SUP'][$kind->value], $request->id)])->save();
        }

        // ------------------------------------------------------------------ in-app notifications
        $user->notifyNow(new UserEvent('payment_successful', ['amount' => '₦8,500,000', 'description' => 'Capital investment — 12-month plan', 'reference' => 'PAY-DEMO', 'next_url' => '/dashboard/capital-investment']), ['database']);
        $user->notifyNow(new UserEvent('listing_approved', ['title' => $published->title, 'listing_id' => $published->id]), ['database']);
        $user->notifyNow(new UserEvent('login_alert', ['device' => 'Chrome on MacOS', 'ip_address' => '102.89.x.x', 'time' => now()->format('M j, Y g:i A')]), ['database']);

        // Recent payments/sign-ups so the dashboard's default 30-day window has data.
        $this->call(DemoRecentActivitySeeder::class);

        // Real images need Cloudinary credentials (SEED_UPLOAD_IMAGES=true).
        if (config('amcom.seed.upload_images')) {
            $this->call(DemoImageSeeder::class);
        }

        $this->command->info('Demo data seeded. Accounts: superadmin@amcom.test, admin@amcom.test, user@amcom.test, new@amcom.test');
    }

    private function account(string $email, string $first, string $last, string $role, string $passwordHash, bool $onboarded = false): User
    {
        $user = User::query()->where('email', $email)->first() ?? new User;
        $user->forceFill([
            'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'phone' => '+2348'.substr(sprintf('%u', crc32($email)), 0, 9),
            'password' => $passwordHash, 'role' => $role, 'status' => UserStatus::Active,
            'email_verified_at' => now(), 'kyc_status' => $onboarded ? KycStatus::Verified : KycStatus::Unverified,
            'profile_completed_at' => $onboarded ? now() : null,
        ])->saveQuietly();

        $profile = $user->profile ?? new UserProfile;
        $profile->forceFill($onboarded ? [
            'date_of_birth' => '1992-05-04', 'gender' => 'female', 'nationality' => 'Nigerian', 'state_of_origin' => 'Rivers', 'lga' => 'Port Harcourt',
            'country' => 'Nigeria', 'state' => 'Rivers', 'city' => 'Port Harcourt', 'address' => '24 Ada George Road, Port Harcourt',
            'next_of_kin_name' => 'Chinedu Okonkwo', 'next_of_kin_relationship' => 'Brother', 'next_of_kin_phone' => '+2348034567890',
            'onboarding_step' => OnboardingStep::Completed->value,
        ] : ['onboarding_step' => OnboardingStep::Identity->value]);
        $user->profile()->save($profile);

        if ($onboarded && ! $user->identityVerifications()->exists()) {
            $user->identityVerifications()->create([
                'provider' => 'seed', 'id_type' => 'nin', 'id_number_last4' => '8901', 'status' => KycStatus::Verified,
                'first_name' => $first, 'last_name' => $last, 'date_of_birth' => '1992-05-04', 'gender' => 'female',
                'nationality' => 'Nigerian', 'verified_at' => now(),
            ]);
        }

        return $user;
    }

    private function estate(string $slug, string $name, string $mode, string $address, string $city, string $state, float $lat, float $lng, string $prefix, int $plots, int $price, int $size): Estate
    {
        $estate = Estate::query()->updateOrCreate(['slug' => $slug], [
            'name' => $name, 'description' => "{$name} is a master-planned residential enclave with secure, well-laid-out plots, good road access and verified title documents.",
            'address' => $address, 'city' => $city, 'state' => $state, 'latitude' => $lat, 'longitude' => $lng,
            'size_sqm' => $size, 'checkout_mode' => $mode, 'max_plots_per_order' => 5, 'is_published' => true,
        ]);

        foreach ([[12, 18], [18, 28], [24, 42], [36, 65]] as [$months, $roi]) {
            $estate->tenures()->updateOrCreate(['months' => $months], ['roi_percent' => $roi]);
        }

        foreach (range(1, $plots) as $i) {
            $code = sprintf('%s%03d', $prefix, $i + 70);
            $estate->plots()->updateOrCreate(['code' => $code], [
                'size_sqm' => $size + ($i % 3) * 50,
                'price' => $price + ($i % 3) * 250_000,
                'status' => $i % 7 === 0 ? PlotStatus::Sold : PlotStatus::Available,
            ]);
        }

        return $estate;
    }

    private function position(User $user, Product $product, int $principal, int $tenure, float $roi, $start, ?Estate $estate = null, array $plots = [], InvestmentStatus $status = InvestmentStatus::Active): Investment
    {
        $start = CarbonImmutable::parse($start)->startOfDay();
        $investment = $user->investments()->create([
            'product' => $product, 'reference' => Reference::temporary(), 'status' => $status, 'estate_id' => $estate?->id,
            'principal' => $principal, 'tenure_months' => $tenure, 'roi_percent' => $roi,
            'projected_return' => (int) round($principal * (100 + $roi) / 100),
            'started_at' => $start->toDateString(),
            'maturity_date' => $status === InvestmentStatus::Matured ? now()->subDays(5)->toDateString() : $start->addMonthsNoOverflow($tenure)->toDateString(),
            'agreement_signed_at' => $start,
        ]);
        $investment->forceFill(['reference' => Reference::yearly($product === Product::Buyback ? 'BB' : 'CI', $investment->id)])->save();

        foreach ($plots as $plot) {
            $investment->plots()->attach($plot->id, ['price' => $plot->price]);
            $plot->forceFill(['status' => PlotStatus::Sold, 'reserved_by_investment_id' => $investment->id])->save();
        }

        $types = $product === Product::Buyback
            ? [DocumentType::PaymentReceipt, DocumentType::BuybackAgreement, DocumentType::PostDatedCheque]
            : [DocumentType::PaymentReceipt, DocumentType::InvestmentContract, DocumentType::InvestmentCertificate];

        foreach ($types as $type) {
            // Demo documents have no file; real ones are generated on verified payment.
            $investment->documents()->save(new Document([
                'user_id' => $user->id, 'type' => $type, 'name' => $type->label(),
                'status' => $type === DocumentType::PostDatedCheque ? DocumentStatus::IssuedAfterSigning : DocumentStatus::PendingReview,
                'disk' => 'documents',
            ]));
        }

        $transaction = Transaction::create([
            'user_id' => $user->id, 'reference' => Reference::payment(), 'provider' => 'paystack', 'provider_reference' => (string) random_int(100000000, 999999999),
            'payable_type' => $product === Product::Buyback ? PayableType::BuybackOrder : PayableType::CapitalInvestment,
            'payable_id' => $investment->id,
            'description' => $product === Product::Buyback ? 'Buyback — '.$estate?->name : 'Capital investment — '.$tenure.'-month plan',
            'amount' => $principal, 'fee' => 2000, 'currency' => 'NGN', 'method' => PaymentMethod::Card, 'channel' => 'card',
            'status' => PaymentStatus::Success, 'gateway_response' => 'Approved', 'paid_at' => $start, 'verified_at' => $start, 'effects_applied_at' => $start,
        ]);
        $transaction->forceFill(['created_at' => $start, 'updated_at' => $start])->saveQuietly();

        return $investment;
    }
}
