<?php

require_once __DIR__.'/../Support/Cards.php';

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Schemastud\Frame\Attributes\WidgetIn;
use Schemastud\Frame\Contracts\ResourceContextContributor;
use Schemastud\Frame\Registry\ContextManifest;
use Splicewire\Beam\Commerce\Money\MoneyPresenter;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardCardSummaryProvider;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Board\Cards\MrrTrendCard;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensMode;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Board\Sources\ProjectionBoardSource;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;
use Splicewire\Beam\Exec\Tests\Support\ProjectedIdCard;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Projection\ProjectionAssumptions;
use Splicewire\Beam\Synthetic\Projection\ProjectionBaseline;
use Splicewire\Beam\Synthetic\Projection\ProjectionEngine;

/*
 * The Projection lens over an in-memory reading: beam-synthetic's golden book (four parties at 2026-10 on the Demo
 * plans' prices, every assumption on, four months ahead), plus a smaller September so the trend has measured history.
 * The as-of instant is 2026-11-15, so the anchor (the last closed month) is 2026-10.
 */

/** The hash beam-synthetic pins for this projection (its ProjectionGoldenTest). */
const PROJECTION_GOLDEN_SHA256 = 'de565344e6ace42383a03aff36d48162175f857379c0e0c147983b45053475b5';

const PROJECTION_AS_OF = '2026-11-15 09:00:00';

function goldenBookConfig(): void
{
    config(['beam.exec.projection' => [
        'horizon_months' => 4,
        'churn_rate' => 0.25,
        'expansion_rate' => 0.02,
        'growth_rate' => 0.25,
        'price_changes' => [
            ['period' => '2027-01', 'rate' => 0.10, 'plan' => 'starter'],
            // Already in the anchor's prices: skipped, not refused.
            ['period' => '2026-10', 'rate' => 5.0],
        ],
        'new_logo_recurring_usd' => 99.0,
        'new_logo_plan' => 'starter',
    ]]);
}

function goldenBookReading(?int &$reads = null): BoardSource
{
    $reads = 0;

    return new class([new PartyPeriodRevenue('acme', '2026-09', 'professional', 379.0, 40.0), new PartyPeriodRevenue('birch', '2026-09', 'starter', 99.0, 6.0), new PartyPeriodRevenue('cobalt', '2026-09', 'professional', 299.0, 18.0),
        // Out of order on purpose, and acme's October in two rows: the baseline sums and sorts.
        new PartyPeriodRevenue('dune', '2026-10', 'starter', 99.0, 0.0), new PartyPeriodRevenue('acme', '2026-10', 'professional', 300.0, 42.5), new PartyPeriodRevenue('birch', '2026-10', 'starter', 99.0, 6.25), new PartyPeriodRevenue('cobalt', '2026-10', 'professional', 299.0, 18.0), new PartyPeriodRevenue('acme', '2026-10', 'professional', 79.0, 0.0), ], $reads) implements BoardSource
        {
            private InMemoryBoardSource $inner;

            public function __construct(array $rows, private int &$reads)
            {
                $this->inner = new InMemoryBoardSource(asOf: CarbonImmutable::parse(PROJECTION_AS_OF), revenue: $rows);
            }

            public function lens(): string
            {
                return 'live';
            }

            public function synthesizedFrom(): ?string
            {
                return null;
            }

            public function projection(): null
            {
                return null;
            }

            public function asOf(): CarbonImmutable
            {
                return $this->inner->asOf();
            }

            public function tenants(): array
            {
                return [];
            }

            public function subscriptions(): array
            {
                return [];
            }

            public function usage(string $month): array
            {
                return [];
            }

            public function revenue(array $months): array
            {
                $this->reads++;

                return $this->inner->revenue($months);
            }

            public function posture(): array
            {
                return [];
            }
        };
}

function goldenBookProjection(?int &$reads = null): ProjectionBoardSource
{
    goldenBookConfig();

    return app(BoardLensResolver::class)->projection(BoardLens::projection(), CarbonImmutable::parse(PROJECTION_AS_OF), goldenBookReading($reads));
}

/** @return list<array{string, int|float|string, ?bool}> */
function idValueForecast(array $figures): array
{
    return array_map(fn (BoardFigureData $f): array => [$f->id, $f->value, $f->forecast], $figures);
}

it('agrees with beam-synthetic\'s engine: the golden projection, byte for byte', function () {
    $source = goldenBookProjection();

    // The same engine, handed the baseline the way ticket 18 prescribes: fromRows over the reading's anchor month.
    $engine = (new ProjectionEngine)->project(
        ProjectionBaseline::fromRows('2026-10', goldenBookReading()->revenue(['2026-10'])),
        app(BoardLensResolver::class)->assumptions('2026-10'),
    );

    expect(hash('sha256', (string) json_encode($source->revenueProjection()->toArray())))->toBe(PROJECTION_GOLDEN_SHA256)
        ->and($source->revenueProjection()->toArray())->toBe($engine->toArray())
        ->and($source->revenue(['2026-11', '2026-12', '2027-01', '2027-02']))->toEqual($engine->projectedRows())
        // A month up to the anchor is the reading's own rows, as it gave them.
        ->and($source->revenue(['2026-10']))->toEqual(goldenBookReading()->revenue(['2026-10']))
        // Nothing after the last projected month.
        ->and($source->revenue(['2027-03']))->toBe([]);
});

it('marks each month of the MRR trend a forecast after the anchor and measured up to it, and agrees with the engine\'s MRR', function () {
    $source = goldenBookProjection();
    $cards = app(BoardCardRegistry::class);
    $trend = $cards->figures($cards->definitions()[array_search('mrr-trend', $cards->cardKeys(), true)], $source);

    expect($source->asOf()->format('Y-m'))->toBe('2027-02')
        ->and($trend[0]->id)->toBe(BoardWatermark::PROJECTED_FIGURE)
        ->and($trend[0]->label)->toBe('Projected — not real data')
        ->and($trend[0]->value)->toBe('Live, October 2026 held flat, 4 months ahead')
        ->and($trend[0]->tone)->toBe('warn')
        ->and($trend[0]->forecast)->toBeNull()
        ->and(array_slice(idValueForecast($trend), -6))->toBe([
            ['2026-09', 777.0, false],
            ['2026-10', 876.0, false],
            // beam-synthetic's golden MRR, month by month.
            ['2026-11', 605.94, true],
            ['2026-12', 614.06, true],
            ['2027-01', 448.85, true],
            ['2027-02', 448.85, true],
        ])
        ->and(count($trend))->toBe(14)
        // The watermark is not a period; nine measured months (2026-02 to the anchor), then four projected.
        ->and(array_column(idValueForecast($trend), 2))->toBe([null, ...array_fill(0, 9, false), ...array_fill(0, 4, true)])
        ->and($trend)->each(fn ($f) => $f->projected->toBeTrue()->synthesized->toBeFalse());
});

it('marks every revenue figure a forecast when it describes a projected month, and measured at horizon 0', function () {
    $source = goldenBookProjection();
    $cards = app(BoardCardRegistry::class);
    $revenue = $cards->figures($cards->definitions()[array_search('revenue', $cards->cardKeys(), true)], $source);
    $byId = collect($revenue)->keyBy('id');

    expect($revenue[0]->id)->toBe('projected')
        ->and($byId['mrr']->value)->toBe(MoneyPresenter::format(448.85))
        ->and($byId['mrr']->forecast)->toBeTrue()
        ->and(collect($revenue)->slice(1)->pluck('forecast')->unique()->all())->toBe([true]);

    config(['beam.exec.projection.horizon_months' => 0]);
    $anchorOnly = app(BoardLensResolver::class)->projection(BoardLens::projection(), CarbonImmutable::parse(PROJECTION_AS_OF), goldenBookReading());
    $atAnchor = $cards->figures($cards->definitions()[array_search('revenue', $cards->cardKeys(), true)], $anchorOnly);

    expect(collect($atAnchor)->keyBy('id')['mrr']->value)->toBe(MoneyPresenter::format(876.0))
        ->and(collect($atAnchor)->slice(1)->pluck('forecast')->unique()->all())->toBe([false])
        ->and($anchorOnly->projection()->describe())->toBe('Live, October 2026 held flat, 0 months ahead');
});

it('reads its reading once, at build, and answers every card after that with zero queries', function () {
    $reads = 0;
    $source = goldenBookProjection($reads);
    $cards = app(BoardCardRegistry::class);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $drawn = [];
    foreach ($cards->definitions() as $definition) {
        $drawn[$definition->key()] = count($cards->figures($definition, $source));
    }

    expect($reads)->toBe(1)
        ->and($queries)->toBe(0)
        // Only the cards that project are measured; the rest are absent, not drawn from present-day facts.
        ->and($drawn)->toBe(['tenants' => 0, 'revenue' => 13, 'mrr-trend' => 14, 'growth' => 0, 'plan-mix' => 0, 'usage' => 0, 'posture' => 0, 'risk' => 0]);
});

it('sends forecast to the dashboard summary under Projection, absent it from the cards that do not project, and sends none under Live', function () {
    app()->instance(BoardSource::class, goldenBookProjection());
    $particles = app(ParticleResourceRegistry::class);
    $summary = fn (string $key) => app(BoardCardSummaryProvider::class)->summary($particles->definition($key, 'exec'));

    $figures = array_map(fn ($f) => $f->toArray(), $summary('exec-mrr-trend')->figures);

    expect($figures[0])->toBe(['key' => 'projected', 'label' => 'Projected — not real data', 'value' => 'Live, October 2026 held flat, 4 months ahead', 'tone' => 'warn'])
        ->and(array_slice($figures, -2))->toBe([
            ['key' => '2027-01', 'label' => 'Jan 2027', 'value' => 448.85, 'tone' => null, 'forecast' => true],
            ['key' => '2027-02', 'label' => 'Feb 2027', 'value' => 448.85, 'tone' => null, 'forecast' => true],
        ])
        ->and($figures[2])->toBe(['key' => '2026-03', 'label' => 'Mar 2026', 'value' => 0.0, 'tone' => null, 'forecast' => false])
        ->and($summary('exec-tenants'))->toBeNull();

    // A board that projects nothing keeps the summary bytes it had: no forecast key at all.
    app()->instance(BoardSource::class, new InMemoryBoardSource(asOf: CarbonImmutable::parse(PROJECTION_AS_OF), revenue: [
        new PartyPeriodRevenue('acme', '2026-11', 'professional', 379.0),
    ]));
    $live = array_map(fn ($f) => $f->toArray(), $summary('exec-mrr-trend')->figures);

    expect(end($live))->toBe(['key' => '2026-11', 'label' => 'Nov 2026', 'value' => 379.0, 'tone' => null])
        ->and(array_map(fn ($f) => $f->toArray(), $summary('exec-revenue')->figures)[0])->not->toHaveKey('forecast');
});

it('refuses every read it does not project, and a month before the window it read', function (string $read) {
    $source = goldenBookProjection();

    expect(fn () => $read === 'usage' ? $source->usage('2026-10') : $source->{$read}())
        ->toThrow(LogicException::class, "The Projection lens projects revenue only, so it cannot answer {$read}()");
})->with(['tenants', 'subscriptions', 'usage', 'posture']);

it('refuses a revenue month before the history it read, naming the window', function () {
    expect(fn () => goldenBookProjection()->revenue(['2025-09']))
        ->toThrow(LogicException::class, 'The projection reads revenue from 2025-10 to 2027-02; 2025-09 is before the months it read from the live reading.');
});

it('reserves the projected watermark\'s figure id', function () {
    $cards = app(BoardCardRegistry::class);
    $cards->add(ProjectedIdCard::class);

    expect(fn () => $cards->figures($cards->definitions()[array_search('projected-id', $cards->cardKeys(), true)], new InMemoryBoardSource))
        ->toThrow(LogicException::class, 'The board card `projected-id` emits the figure id `projected`');
});

it('resolves the Projection lens from its cookie, over the default reading, at a declared portfolio\'s rates', function () {
    $lens = fn (?string $cookie): BoardLens => app(BoardLensResolver::class)->resolve(tap(Request::create('/exec/dashboard'), fn (Request $r) => $cookie === null ? null : $r->cookies->set(BoardLens::COOKIE, $cookie)));

    expect($lens('projection'))->mode->toBe(BoardLensMode::Projection)->portfolio->toBeNull()->chosen->toBeTrue()
        ->and($lens('projection:demo-mixed-book'))->mode->toBe(BoardLensMode::Projection)->portfolio->toBe('demo-mixed-book')
        ->and($lens('projection:not-declared'))->mode->toBe(BoardLensMode::Live)->chosen->toBeFalse()
        ->and(BoardLens::projection()->projects())->toBeTrue()
        ->and(BoardLens::projection()->synthesizes())->toBeFalse();

    config(['beam.exec.projection.portfolio' => 'demo-mixed-book']);
    expect($lens('projection'))->portfolio->toBe('demo-mixed-book');

    config(['beam.exec.projection.portfolio' => 'not-declared']);
    expect($lens('projection'))->mode->toBe(BoardLensMode::Live);

    // The portfolio's own rates win where it states them; the rest stay the configured ones.
    config(['beam.exec.projection' => ['horizon_months' => 3, 'churn_rate' => 0.5, 'expansion_rate' => 0.5, 'growth_rate' => 0.1]]);
    $resolver = app(BoardLensResolver::class);
    $portfolio = app(PortfolioRegistry::class)->get('demo-mixed-book');

    expect($resolver->assumptions('2026-10', $portfolio))
        ->toEqual(new ProjectionAssumptions(horizonMonths: 3, churnRate: 0.02, expansionRate: 0.01, growthRate: 0.1))
        ->and($resolver->assumptions('2026-10'))
        ->toEqual(new ProjectionAssumptions(horizonMonths: 3, churnRate: 0.5, expansionRate: 0.5, growthRate: 0.1));

    $source = $resolver->projection(BoardLens::projection('demo-mixed-book'), CarbonImmutable::parse(PROJECTION_AS_OF), goldenBookReading());

    expect($source->projection()->describe())->toBe("Live, October 2026 held flat, 3 months ahead at Demo mixed book's rates")
        ->and($source->lens())->toBe('projection')
        ->and($source->synthesizedFrom())->toBeNull();
});

it('chooses the trend-card widget for the MRR trend card only, through frame\'s context port', function () {
    $manifest = app(ContextManifest::class);
    $trend = $manifest->forResource(BoardFigureData::class, key: 'exec-mrr-trend')['byNode'][''] ?? [];
    $revenue = $manifest->forResource(BoardFigureData::class, key: 'exec-revenue')['byNode'][''] ?? [];

    expect($trend['summary']['widget'] ?? null)->toBe('trend-card')
        ->and($revenue)->not->toHaveKey('summary')
        ->and(app(ResourceContextContributor::class)->nodesFor('records'))->toBeArray()
        ->and((new ReflectionClass(MrrTrendCard::class))->getAttributes(WidgetIn::class))->toHaveCount(1);
});
