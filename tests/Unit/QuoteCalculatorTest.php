<?php

namespace Tests\Unit;

use App\Services\Investments\QuoteCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class QuoteCalculatorTest extends TestCase
{
    public function test_quote_matches_contract_examples(): void
    {
        $calc = new QuoteCalculator;

        // Contract §6.5: ₦2,000,000 @ 18% for 12 months → ₦2,360,000.
        $quote = $calc->quote(2_000_000, 12, 18, CarbonImmutable::parse('2026-10-12'));
        $this->assertSame(2_360_000, $quote['projected_return']);
        $this->assertSame(360_000, $quote['profit']);
        $this->assertSame('2027-10-12', $quote['maturity_date']);

        // Contract §7.3: ₦1,000,000 @ 18% for 6 months → ₦1,180,000, maturing 2027-04-12.
        $quote = $calc->quote(1_000_000, 6, 18, CarbonImmutable::parse('2026-10-12'));
        $this->assertSame(1_180_000, $quote['projected_return']);
        $this->assertSame('2027-04-12', $quote['maturity_date']);
    }

    public function test_month_end_does_not_overflow(): void
    {
        $this->assertSame('2027-02-28', (new QuoteCalculator)->maturityDate(12, CarbonImmutable::parse('2026-02-28')));
        $this->assertSame('2026-02-28', (new QuoteCalculator)->maturityDate(1, CarbonImmutable::parse('2026-01-31')));
    }
}
