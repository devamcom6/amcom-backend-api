<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Testimonial;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Seeder;

/** Public content: FAQs, testimonials, legal pages and home stats (idempotent). */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            'buyback' => [
                ['Is this an investment with interest?', 'No. This is not an interest-based product. You purchase a plot of land outright, and we contractually agree to buy it back from you at a fixed, higher price at the end of your chosen tenure.'],
                ['What documents do I receive?', 'You receive a payment receipt, a signed buy-back agreement and a post-dated cheque for the buy-back amount, all available from your dashboard.'],
                ['Can I buy more than one plot?', 'Yes. You can select several plots in a single order, up to the limit shown for each estate.'],
                ['What happens at maturity?', 'At the end of your tenure, the company buys the plot back at the agreed price and the payout is sent to your nominated payout account.'],
            ],
            'capital_investment' => [
                ['What is the minimum investment?', 'The minimum investment is ₦1,000,000. Investments above ₦50,000,000 are handled directly by our investment team.'],
                ['Is my return fixed?', 'Yes. Your return is fixed for the tenure you choose and is shown before you sign the agreement.'],
                ['Can I reinvest at maturity?', 'Yes. When your plan matures you can request a payout or reinvest your capital and returns into a new plan.'],
            ],
            'property_management' => [
                ['How do I list my property?', 'Create an account, tell us about your property, upload photos and pay the one-time registration fee. Our team inspects and verifies the property within 3–5 business days.'],
                ['What does the listing fee cover?', 'The fee covers inspection, verification and marketing of your property on the Amcom marketplace.'],
                ['Can I book an inspection before buying or renting?', 'Yes. Every listing has a Book inspection option for in-person or virtual viewings.'],
            ],
            'general' => [
                ['Is Amcom regulated?', 'Amcom is operated by HOUVINCITY Global Limited, a registered company in Nigeria. All transactions are documented with signed agreements.'],
                ['How do I contact support?', 'Email info@houvincity.com, call +234 707 469 3320, or use Help & Support in your dashboard.'],
            ],
        ];

        foreach ($faqs as $product => $items) {
            foreach ($items as $position => [$question, $answer]) {
                Faq::query()->updateOrCreate(
                    ['product' => $product, 'question' => $question],
                    ['answer' => $answer, 'position' => $position + 1, 'is_published' => true],
                );
            }
        }

        $testimonials = [
            ['Amcom helped us find the perfect home for our family. The process was transparent from start to finish.', 'Adaeze Okonkwo', 'Buyer at Palm Gardens Estate'],
            ['The buyback plan gave me a clear, documented return. I received my payout exactly on the maturity date.', 'Tunde Bakare', 'Buyback investor'],
            ['Listing my apartment was easy and the verification gave buyers real confidence.', 'Chiamaka Eze', 'Property owner, Lekki'],
        ];

        foreach ($testimonials as $position => [$quote, $author, $title]) {
            Testimonial::query()->updateOrCreate(['author_name' => $author], [
                'quote' => $quote, 'author_title' => $title, 'position' => $position + 1, 'is_published' => true,
            ]);
        }

        $pages = [
            'privacy-policy' => ['Privacy Policy', '<h2>Overview</h2><p>This policy explains how Amcom collects, uses and protects your personal information.</p><h2>Data we collect</h2><ul><li>Account and contact details</li><li>Identity verification results</li><li>Transaction records</li></ul><h2>Your rights</h2><p>You can request access to, correction of, or deletion of your data by contacting <a href="mailto:info@houvincity.com">info@houvincity.com</a>.</p>'],
            'terms-of-service' => ['Terms of Service', '<h2>Agreement</h2><p>By using Amcom you agree to these terms.</p><h2>Investments</h2><p>Each investment is governed by the agreement you sign at checkout.</p><h2>Payments</h2><p>Payments are processed securely by Paystack.</p>'],
            'cookie-policy' => ['Cookie Policy', '<h2>Cookies</h2><p>We use essential cookies to keep you signed in and optional analytics cookies to improve the service.</p>'],
        ];

        foreach ($pages as $slug => [$title, $html]) {
            Page::query()->updateOrCreate(['slug' => $slug], ['title' => $title, 'body_html' => HtmlSanitizer::clean($html)]);
        }

        Setting::query()->firstOrCreate(['key' => 'home_stats'], ['value' => [
            ['value' => '15+', 'label' => 'Years of Operating'],
            ['value' => '1200+', 'label' => 'Clients served'],
            ['value' => '₦2B+', 'label' => 'Value under management'],
            ['value' => '500+', 'label' => 'Properties listed and sold'],
        ]]);
    }
}
