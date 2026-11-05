<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Board\Cards\GrowthCard;
use Splicewire\Beam\Exec\Board\Cards\PlanMixCard;
use Splicewire\Beam\Exec\Board\Cards\UsageCard;
use Splicewire\Beam\Exec\Board\Figures;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;

/*
 * The growth, plan-mix and usage cards, each measured against an in-memory source: every figure is checked by hand,
 * and every measurement runs inside DB::listen and must issue no query.
 */

/** @return array<string, int|float|string> each figure's value by id, measured with zero queries */
function measureCard(BoardMetric $card, InMemoryBoardSource $source): array
{
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $figures = $card->measure($source);

    expect($queries)->toBe(0);

    return collect($figures)->pluck('value', 'id')->all();
}

function tenantAt(string $id, ?string $created, bool $synthetic = false, ?string $broker = null): BoardTenant
{
    return new BoardTenant(
        id: $id,
        name: ucfirst($id),
        status: 'active',
        synthetic: $synthetic,
        createdAt: $created === null ? null : CarbonImmutable::parse($created),
        brokerId: $broker,
    );
}

function planOf(string $tenant, string $plan, string $started, ?string $ended = null, int $commitment = 1, bool $paused = false): BoardSubscription
{
    return new BoardSubscription(
        tenantId: $tenant,
        planKey: $plan,
        planName: ucfirst($plan),
        startedAt: CarbonImmutable::parse($started),
        endedAt: $ended === null ? null : CarbonImmutable::parse($ended),
        commitmentMonths: $commitment,
        paused: $paused,
    );
}

it('measures growth this month against the base the month began with, and over twelve months', function () {
    // As of 2026-08-20: 4 tenants existed before August (one with no known date), 2 arrived in August, 1 in July.
    $source = new InMemoryBoardSource(tenants: [
        tenantAt('old', '2025-03-01'),
        tenantAt('undated', null),
        tenantAt('spring', '2026-04-10'),
        tenantAt('july', '2026-07-31 23:59:59'),
        tenantAt('aug-1', '2026-08-01 00:00:00'),
        tenantAt('aug-2', '2026-08-19'),
        tenantAt('future', '2026-08-25'), // after asOf: not yet in any base
    ]);

    expect(measureCard(new GrowthCard, $source))->toBe([
        'month-growth' => '+50.0%',   // 4 → 6
        'new-this-month' => 2,
        'new-last-month' => 1,
        'new-12-months' => 4,         // spring, july, aug-1, aug-2 (old is 2025-03, before 2025-08-20)
        'year-growth' => '+200.0%',   // base 2025-08-20 = old + undated = 2 → 6
    ]);
});

it('prints an undefined rate, not a division by zero, when there was no base to grow from', function () {
    $source = new InMemoryBoardSource(tenants: [tenantAt('first', '2026-08-05')]);
    $figures = measureCard(new GrowthCard, $source);

    expect($figures['month-growth'])->toBe(Figures::UNDEFINED)
        ->and($figures['year-growth'])->toBe(Figures::UNDEFINED)
        ->and($figures['new-this-month'])->toBe(1)
        ->and(measureCard(new GrowthCard, new InMemoryBoardSource))->toMatchArray(['month-growth' => Figures::UNDEFINED, 'new-this-month' => 0]);
});

it('draws the plan mix in force as of the board instant, largest plan first, counting tenants not rows', function () {
    $source = new InMemoryBoardSource(
        tenants: [
            tenantAt('a', '2026-01-01'), tenantAt('b', '2026-01-01'), tenantAt('c', '2026-01-01'),
            tenantAt('d', '2026-01-01'), tenantAt('e', '2026-01-01'), tenantAt('none', '2026-01-01'),
            tenantAt('child', '2026-01-01', broker: 'a'),
        ],
        subscriptions: [
            planOf('a', 'pro', '2026-01-01', commitment: 12),
            planOf('a', 'pro', '2026-03-01', commitment: 12),          // a second in-force row: still one tenant
            planOf('b', 'pro', '2026-02-01', paused: true),
            planOf('c', 'starter', '2026-02-01'),
            planOf('d', 'starter', '2026-02-01', ended: '2026-08-01'),  // lapsed before asOf
            planOf('d', 'team', '2026-08-01', commitment: 24),          // moved to team
            planOf('e', 'team', '2026-09-01'),                          // starts after asOf
            planOf('e', 'starter', '2026-01-01', ended: '2026-09-30'),  // a scheduled lapse is still in force
        ],
    );

    $figures = measureCard(new PlanMixCard, $source);

    expect(array_keys($figures))->toBe(['subscribed', 'plan-pro', 'plan-starter', 'plan-team', 'annual-share', 'paused', 'unsubscribed'])
        ->and($figures)->toBe([
            'subscribed' => 5,
            'plan-pro' => 2,
            'plan-starter' => 2,          // c and e
            'plan-team' => 1,             // d
            'annual-share' => '40.0%',    // a and d of 5
            'paused' => 1,
            'unsubscribed' => 1,          // `none`; the Brokered Tenant is billed through its broker
        ]);
});

it('draws an empty plan mix without a rate it cannot compute', function () {
    $figures = (new PlanMixCard)->measure(new InMemoryBoardSource(tenants: [tenantAt('solo', '2026-01-01')]));
    $byId = collect($figures)->keyBy('id');

    expect($byId->keys()->all())->toBe(['subscribed', 'annual-share', 'paused', 'unsubscribed'])
        ->and($byId['annual-share']->value)->toBe(Figures::UNDEFINED)
        ->and($byId['unsubscribed']->value)->toBe(1)
        ->and($byId['paused']->tone)->toBeNull()
        ->and($byId['subscribed']->tone)->toBe(SummaryFigureData::TONE_ACTIVE);
});

it('measures usage cost this month against last month, the tenants behind it and the costliest meter', function () {
    $source = new InMemoryBoardSource(usage: [
        new BoardUsage('a', '2026-08', 'openai.tokens', 3.0, 3000),
        new BoardUsage('b', '2026-08', 'openai.tokens', 1.5, 1000),
        new BoardUsage('b', '2026-08', 'storage.gb', 4.0, 12),
        new BoardUsage('c', '2026-08', 'storage.gb', 0.0, 0),      // recorded, but nothing used
        new BoardUsage('a', '2026-07', 'openai.tokens', 5.0, 5000),
        new BoardUsage('a', '2026-06', 'openai.tokens', 99.0, 1),  // not either month
    ]);

    expect(measureCard(new UsageCard, $source))->toBe([
        'cost-this-month' => '$8.50',
        'cost-last-month' => '$5.00',
        'cost-change' => '+70.0%',
        'active-tenants' => 2,
        'top-meter' => 'openai.tokens',   // 4.50 against storage's 4.00
    ]);
});

it('reads a quiet month as zero cost with no meter and no rate', function () {
    expect(measureCard(new UsageCard, new InMemoryBoardSource))->toBe([
        'cost-this-month' => '$0.00',
        'cost-last-month' => '$0.00',
        'cost-change' => Figures::UNDEFINED,
        'active-tenants' => 0,
        'top-meter' => Figures::UNDEFINED,
    ]);
});

it('reads "this month" off the source, not the wall clock', function () {
    $source = fn (string $asOf) => new InMemoryBoardSource(
        tenants: [tenantAt('x', '2025-02-10')],
        usage: [new BoardUsage('x', '2025-02', 'openai.tokens', 2.0, 1)],
        asOf: CarbonImmutable::parse($asOf),
    );

    expect(measureCard(new GrowthCard, $source('2025-02-28'))['new-this-month'])->toBe(1)
        ->and(measureCard(new UsageCard, $source('2025-02-28'))['cost-this-month'])->toBe('$2.00')
        ->and(measureCard(new UsageCard, $source('2025-03-05'))['cost-last-month'])->toBe('$2.00');
});
