<?php

require_once __DIR__.'/../Support/ScenarioPlans.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Board\Sources\ScenarioPlan;
use Splicewire\Beam\Exec\Board\Sources\SyntheticBoardSource;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Synthetic\Roster\ScenarioProjector;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/*
 * The Scenario lens's source over beam-synthetic's Demo portfolio (`demo-mixed-book`: 3 Quillmere Studio from month
 * 1, 4 Tessaline Works from month 3, 2 Orrowby & Hart from month 6, each a 12-month blueprint, so 17 months).
 */
const SCENARIO_AS_OF = '2026-08-20 12:00:00';

function scenarioSource(?CarbonImmutable $asOf = null): SyntheticBoardSource
{
    return app(BoardLensResolver::class)->source(BoardLens::scenario('demo-mixed-book'), $asOf ?? CarbonImmutable::parse(SCENARIO_AS_OF));
}

function demoRoster(): SyntheticRoster
{
    return SyntheticRoster::ofPortfolio(app(PortfolioRegistry::class)->get('demo-mixed-book'), app(BlueprintRegistry::class));
}

beforeEach(function () {
    seedScenarioPlans();
});

it('builds a Scenario board from the host\'s price list in one plans read, then answers every card with zero queries', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $source = scenarioSource();

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('"plans"');

    $queries = [];
    $cards = app(BoardCardRegistry::class);
    $drawn = [];

    foreach ($cards->definitions() as $definition) {
        $figures = $cards->figures($definition, $source);
        $drawn[$definition->key()] = $figures;

        expect($figures)->not->toBe([])
            ->each(fn ($figure) => $figure->toBeInstanceOf(BoardFigureData::class)->synthesized->toBeTrue())
            ->and($figures[0]->id)->toBe(BoardWatermark::FIGURE)
            ->and($figures[0]->label)->toBe('Synthesized — not real data')
            ->and($figures[0]->value)->toBe('Demo mixed book');
    }

    expect(array_keys($drawn))->toBe(['tenants', 'revenue', 'growth', 'plan-mix', 'usage', 'posture', 'risk'])
        ->and($queries)->toBe([]);
});

it('lays the portfolio\'s window out to end with the as-of month, each member starting at its offset', function () {
    $source = scenarioSource();
    $tenants = $source->tenants();

    expect($source->lens())->toBe('scenario')
        ->and($source->synthesizedFrom())->toBe('Demo mixed book')
        ->and($source->asOf()->toDateTimeString())->toBe(SCENARIO_AS_OF)
        ->and(array_map(fn (BoardTenant $t): string => $t->id, $tenants))->toBe([
            'demo-quillmere-studio-1', 'demo-quillmere-studio-2', 'demo-quillmere-studio-3',
            'demo-tessaline-works-1', 'demo-tessaline-works-2', 'demo-tessaline-works-3', 'demo-tessaline-works-4',
            'demo-orrowby-hart-1', 'demo-orrowby-hart-2',
        ])
        ->and(array_unique(array_map(fn (BoardTenant $t): string => $t->synthetic ? 'synthetic' : 'real', $tenants)))->toBe(['synthetic'])
        // 17 months ending 2026-08 start 2025-04; offsets 0, 2 and 5.
        ->and(array_values(array_unique(array_map(fn (BoardTenant $t): string => $t->createdAt->toDateString(), $tenants))))
        ->toBe(['2025-04-01', '2025-06-01', '2025-09-01']);

    $subscriptions = $source->subscriptions();

    expect(array_map(fn (BoardSubscription $s): array => [$s->tenantId, $s->planKey, $s->planName, $s->startedAt->format('Y-m'), $s->endedAt], [$subscriptions[0], $subscriptions[3]]))->toBe([
        ['demo-quillmere-studio-1', 'professional', 'Professional', '2025-04', null],
        ['demo-tessaline-works-1', 'starter', 'Starter', '2025-06', null],
    ]);
});

it('reads the same usage curves beam-synthetic\'s own projector runs (a second instrument)', function () {
    $source = scenarioSource();
    $roster = demoRoster();
    $projection = app(ScenarioProjector::class)->project($roster, CarbonImmutable::parse('2025-04-01'), $roster->length());

    expect($projection->from)->toBe('2025-04')->and($projection->months)->toBe(17);

    $compared = 0;
    foreach ($projection->tenants as $tenant) {
        foreach ($tenant->projection as $month) {
            /** @var ScenarioMonthData $month */
            $rows = array_values(array_filter($source->usage($month->month), fn (BoardUsage $u): bool => $u->tenantId === $tenant->tenant));

            expect($rows)->toHaveCount(1)
                ->and($rows[0]->costUsd)->toBe($month->usageCostUsd)
                ->and($rows[0]->meter)->toBe('openai.tokens')
                ->and($rows[0]->quantity)->toBe(round($month->usageCostUsd * 1000));
            $compared++;
        }
    }

    // 3 × 17 + 4 × 15 + 2 × 12 member-months, and nothing after the as-of month.
    expect($compared)->toBe(135)
        ->and($source->usage('2026-09'))->toBe([])
        ->and($source->usage('2025-03'))->toBe([]);
});

it('prices each member-month with the plan in force through beam-commerce\'s pricer, recurring apart from usage', function () {
    $source = scenarioSource();
    $roster = demoRoster();
    $series = $roster->series(app(ScenarioExpander::class), $roster->members[0], $roster->forwardMonths(CarbonImmutable::parse('2025-04-01'), 17));
    $month = '2026-08';
    $point = $series[$month];

    $rows = $source->revenue(['2026-07', $month, '2026-09']);
    $row = collect($rows)->first(fn (PartyPeriodRevenue $r): bool => $r->partyId === 'demo-quillmere-studio-1' && $r->period === $month);

    // Professional: $99 fee + $12 × seats over 5; usage at a 1.5 markup on the month's provider cost.
    expect($row->planKey)->toBe('professional')
        ->and($row->recurringUsd)->toBe(99.0 + 12.0 * max(0, $point->seatCount - 5))
        ->and($row->usageUsd)->toBe($point->usageCostUsd * 1.5)
        ->and($row->billedUsd)->toBeNull()
        ->and($row->paidUsd)->toBeNull()
        // 9 members × 2 months; no row after the as-of month.
        ->and($rows)->toHaveCount(18)
        ->and(collect($rows)->where('planKey', 'starter')->pluck('recurringUsd')->unique()->all())->toBe([29.0])
        ->and(collect($rows)->pluck('period')->unique()->values()->all())->toBe(['2026-07', '2026-08']);
});

it('ends a hard-churned member\'s subscription and revenue at its churn month, and keeps its later usage at zero', function () {
    $blueprint = SyntheticTenantBlueprintData::from([
        'id' => 'demo-churner', 'name' => 'Churner', 'ownerName' => 'A', 'ownerEmail' => 'a@churner.example',
        'scaffoldPackSlug' => null, 'planSlug' => 'starter', 'scenario' => 'steady-ramp', 'windowMonths' => 6,
        'targetMonthlyUsageCostUsd' => 10.0, 'startingSeats' => 1, 'endingSeats' => 2, 'endedAtMonth' => 4,
    ]);
    $source = new SyntheticBoardSource(
        roster: SyntheticRoster::ofBlueprint($blueprint),
        label: 'Churner',
        expander: app(ScenarioExpander::class),
        plans: ['starter' => new ScenarioPlan('starter', 'Starter', [['type' => 'SubscriptionFee', 'config' => ['monthly_price' => 29.0, 'annual_monthly_price' => 24.0]]])],
        asOf: CarbonImmutable::parse('2026-06-10'),
    );

    // Six months 2026-01..06; churned in month 4.
    expect($source->subscriptions()[0]->endedAt->toDateString())->toBe('2026-04-30')
        ->and(array_map(fn (PartyPeriodRevenue $r): string => $r->period, $source->revenue(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'])))
        ->toBe(['2026-01', '2026-02', '2026-03', '2026-04'])
        ->and($source->usage('2026-05')[0]->costUsd)->toBe(0.0)
        ->and($source->usage('2026-04')[0]->costUsd)->toBeGreaterThan(0.0);
});

it('refuses to price a scenario whose plan the host does not have, naming it, rather than pricing it at zero', function () {
    DB::table('plans')->where('slug', 'starter')->delete();

    $source = scenarioSource();

    expect($source->tenants())->toHaveCount(9) // every other read still answers
        ->and(fn () => $source->revenue(['2026-08']))
        ->toThrow(InvalidArgumentException::class, "no plan with the slug 'starter' (named by demo-tessaline-works). Its price list has: professional.");
});

it('reports no posture: a scenario models customers, not the estate', function () {
    $source = scenarioSource();
    $posture = app(BoardCardRegistry::class)->figures(app(BoardCardRegistry::class)->definitions()[5], $source);

    expect($source->posture())->toBe([])
        ->and(array_map(fn (BoardFigureData $f): array => [$f->id, $f->value], $posture))->toBe([
            ['synthesized', 'Demo mixed book'],
            ['status', 'Unmeasured'],
            ['passing', 0],
            ['warnings', 0],
            ['failing', 0],
            ['unmeasured', 0],
        ]);
});
