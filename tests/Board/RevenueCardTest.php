<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Exec\Board\Cards\RevenueCard;
use Splicewire\Beam\Exec\Board\Figures;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;

/*
 * The revenue card against an in-memory source: thirteen months ending with the as-of month (2026-08), worked by
 * hand, measured inside DB::listen with no query.
 */

/** @return array{0: array<string, int|float|string>, 1: array<string, string|null>} values and tones by id */
function measureRevenue(array $rows, string $asOf = '2026-08-20 12:00:00'): array
{
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $figures = collect((new RevenueCard)->measure(new InMemoryBoardSource(asOf: CarbonImmutable::parse($asOf), revenue: $rows)));

    expect($queries)->toBe(0);

    return [$figures->pluck('value', 'id')->all(), $figures->pluck('tone', 'id')->all()];
}

it('reads the thirteen months ending with the as-of month, across a year boundary', function () {
    $months = RevenueCard::months(new InMemoryBoardSource(asOf: CarbonImmutable::parse('2026-01-31 23:00:00')));

    expect($months)->toHaveCount(13)
        ->and($months[0])->toBe('2025-01')
        ->and($months[12])->toBe('2026-01')
        ->and(array_unique($months))->toBe($months);
});

it('draws MRR, its movements this month, logo churn and twelve-month retention', function () {
    $rows = [
        // The opening month (2025-08): a and b pay, 200 in all.
        new PartyPeriodRevenue('a', '2025-08', 'pro', 100.0),
        new PartyPeriodRevenue('b', '2025-08', 'pro', 100.0),
        // Last month (2026-07): a, b, c and d pay; e paid in 2026-03 and left.
        new PartyPeriodRevenue('e', '2026-03', 'starter', 30.0),
        new PartyPeriodRevenue('a', '2026-07', 'pro', 100.0),
        new PartyPeriodRevenue('b', '2026-07', 'pro', 100.0),
        new PartyPeriodRevenue('c', '2026-07', 'pro', 50.0),
        new PartyPeriodRevenue('d', '2026-07', 'starter', 25.0),
        // This month: a expands, b contracts, c churns, d holds, e returns, f arrives.
        new PartyPeriodRevenue('a', '2026-08', 'team', 150.0, usageUsd: 2.5),
        new PartyPeriodRevenue('b', '2026-08', 'starter', 60.0, usageUsd: 1.0),
        new PartyPeriodRevenue('d', '2026-08', 'starter', 25.0),
        new PartyPeriodRevenue('e', '2026-08', 'starter', 30.0),
        new PartyPeriodRevenue('f', '2026-08', 'starter', 10.0),
    ];

    [$values, $tones] = measureRevenue($rows);

    expect($values)->toBe([
        'mrr' => '$275.00',               // 150 + 60 + 25 + 30 + 10
        'arr' => '$3,300.00',
        'net-new-mrr' => '$0.00',         // 275 − 275
        'new-mrr' => '$10.00',            // f
        'expansion-mrr' => '$50.00',      // a
        'reactivation-mrr' => '$30.00',   // e, who paid in 2026-03
        'contraction-mrr' => '$40.00',    // b
        'churned-mrr' => '$50.00',        // c
        'paying' => 5,
        'logo-churn' => '25.0%',          // c of a, b, c, d
        'nrr-12' => '105.0%',             // a and b: 200 then, 210 now
        'usage-revenue' => '$3.50',
    ])
        ->and($tones['churned-mrr'])->toBe(SummaryFigureData::TONE_WARN)
        ->and($tones['nrr-12'])->toBe(SummaryFigureData::TONE_ACTIVE)
        ->and($tones['net-new-mrr'])->toBeNull();
});

it('reads a book with no revenue as zeros and undefined rates', function () {
    [$values, $tones] = measureRevenue([]);

    expect($values)->toMatchArray([
        'mrr' => '$0.00', 'arr' => '$0.00', 'paying' => 0,
        'logo-churn' => Figures::UNDEFINED, 'nrr-12' => Figures::UNDEFINED,
    ])
        ->and($tones['nrr-12'])->toBeNull()
        ->and($tones['churned-mrr'])->toBeNull();
});

it('warns on shrinking MRR and retention below one', function () {
    [$values, $tones] = measureRevenue([
        new PartyPeriodRevenue('a', '2025-08', 'pro', 100.0),
        new PartyPeriodRevenue('a', '2026-07', 'pro', 100.0),
        new PartyPeriodRevenue('a', '2026-08', 'starter', 40.0),
    ]);

    expect($values['net-new-mrr'])->toBe('-$60.00')
        ->and($values['nrr-12'])->toBe('40.0%')
        ->and($tones['net-new-mrr'])->toBe(SummaryFigureData::TONE_WARN)
        ->and($tones['nrr-12'])->toBe(SummaryFigureData::TONE_WARN);
});
