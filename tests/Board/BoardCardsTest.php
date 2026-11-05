<?php

require_once __DIR__.'/../Support/Cards.php';

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Schemastud\Frame\Data\SummaryFigureData;
use Schemastud\Frame\Data\SummaryResponseData;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Exec\Board\BoardCardBacking;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardCardSummaryProvider;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Board\Cards\GrowthCard;
use Splicewire\Beam\Exec\Board\Cards\MrrTrendCard;
use Splicewire\Beam\Exec\Board\Cards\PlanMixCard;
use Splicewire\Beam\Exec\Board\Cards\RevenueCard;
use Splicewire\Beam\Exec\Board\Cards\TenantsCard;
use Splicewire\Beam\Exec\Board\Cards\UsageCard;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Posture\PostureSignal;
use Splicewire\Beam\Exec\Tests\Support\BadKeyCard;
use Splicewire\Beam\Exec\Tests\Support\DashboardKeyCard;
use Splicewire\Beam\Exec\Tests\Support\DuplicateTenantsCard;
use Splicewire\Beam\Exec\Tests\Support\EmptyCard;
use Splicewire\Beam\Exec\Tests\Support\GatedCard;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;
use Splicewire\Beam\Exec\Tests\Support\LeakyCard;
use Splicewire\Beam\Exec\Tests\Support\PipelineCard;
use Splicewire\Beam\Exec\Tests\Support\UndeclaredCard;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Run the provider's card sweep again, as its `booted()` callback does for a card added after the boot link. */
function sweepBoardCards(): void
{
    $provider = app()->getProvider(BeamExecServiceProvider::class);

    Closure::bind(fn () => $this->registerBoardCardResources(), $provider, $provider::class)();
}

function boardTenant(string $id, string $status = 'active', bool $synthetic = false, bool $suspended = false, ?string $created = '2020-01-15'): BoardTenant
{
    return new BoardTenant(
        id: $id,
        name: ucfirst($id),
        status: $status,
        synthetic: $synthetic,
        suspended: $suspended,
        createdAt: $created === null ? null : CarbonImmutable::parse($created),
    );
}

it('reads the built-in cards from config under the card key their attribute declares', function () {
    $cards = app(BoardCardRegistry::class);

    expect($cards->cardKeys())->toBe(['tenants', 'revenue', 'mrr-trend', 'growth', 'plan-mix', 'usage', 'posture', 'risk'])
        ->and($cards->resolve('tenants'))->toBe(TenantsCard::class)
        ->and($cards->resolve('revenue'))->toBe(RevenueCard::class)
        ->and($cards->resolve('mrr-trend'))->toBe(MrrTrendCard::class)
        ->and($cards->resolve('growth'))->toBe(GrowthCard::class)
        ->and($cards->resolve('plan-mix'))->toBe(PlanMixCard::class)
        ->and($cards->resolve('usage'))->toBe(UsageCard::class)
        ->and($cards->registrantOf('tenants'))->toBe('config:beam.exec.cards')
        ->and($cards->registrantOf('usage'))->toBe('config:beam.exec.cards');
});

it('draws each card as a read-only exec-{key} resource in the exec realm, seated in the board section', function () {
    $particles = app(ParticleResourceRegistry::class);

    expect($particles->keysForRealm('exec'))->toContain('exec-dashboard', 'exec-tenants');

    $resource = $particles->get('exec-tenants');

    expect($resource->backing)->toBeInstanceOf(BoardCardBacking::class)
        ->and($resource->backing->card)->toBe('tenants')
        ->and($resource->data)->toBe(BoardFigureData::class)
        ->and($resource->label)->toBe('Tenants')
        ->and($resource->icon)->toBe('Building2')
        ->and($resource->section)->toBe('board')
        ->and($resource->navOrder)->toBe(10)
        ->and($resource->policy)->toBe('entitlement:exec.view')
        ->and($resource->summaryProvider)->toBe(BoardCardSummaryProvider::class)
        ->and($resource->readOnly)->toBeTrue()
        ->and($resource->showable)->toBeFalse()
        ->and($resource->isFramed())->toBeTrue()
        ->and($particles->registrantOf('exec-tenants'))->toBe('splicewire/laravel-beam-exec');
});

it('gates a card on its own ability when it declares one, and on the realm gate otherwise', function () {
    app(BoardCardRegistry::class)->add(GatedCard::class);
    sweepBoardCards();

    expect(app(ParticleResourceRegistry::class)->get('exec-payroll')->policy)->toBe('exec.payroll');
});

it('draws a card a host adds after boot, once, through the booted() sweep', function () {
    app(BoardCardRegistry::class)->add(PipelineCard::class);
    sweepBoardCards();
    sweepBoardCards();

    $particles = app(ParticleResourceRegistry::class);

    expect(array_count_values($particles->keysForRealm('exec'))['exec-pipeline'] ?? 0)->toBe(1)
        ->and($particles->get('exec-pipeline')->navOrder)->toBe(5);
});

it('lists a card\'s figures as its rows and draws the same figures as its dashboard summary', function () {
    app()->instance(BoardSource::class, new InMemoryBoardSource([
        boardTenant('a'),
        boardTenant('b', synthetic: true),
        boardTenant('c', status: 'provisioning', created: 'now'),
        boardTenant('d', suspended: true, created: null),
    ]));

    $resource = app(ParticleResourceRegistry::class)->get('exec-tenants');
    $rows = $resource->backing->records([], null, 1)->items();

    expect(array_map(fn (BoardFigureData $f): array => [$f->id, $f->value, $f->tone], $rows))->toBe([
        ['total', 4, null],
        ['active', 2, SummaryFigureData::TONE_ACTIVE],
        ['new', 1, SummaryFigureData::TONE_BUSY],
        ['synthetic', 1, null],
    ]);

    $definition = app(ParticleResourceRegistry::class)->definition('exec-tenants', 'exec');
    $summary = app(BoardCardSummaryProvider::class)->summary($definition);

    expect($summary)->toBeInstanceOf(SummaryResponseData::class)
        ->and($summary->key)->toBe('exec-tenants')
        ->and($summary->label)->toBe('Tenants')
        ->and($summary->icon)->toBe('Building2')
        ->and(array_map(fn (SummaryFigureData $f): array => [$f->key, $f->label, $f->value, $f->tone], $summary->figures))
        ->toBe(array_map(fn (BoardFigureData $f): array => [$f->id, $f->label, $f->value, $f->tone], $rows));
});

it('declines a summary for a card that measures nothing, and for a key that names no card', function () {
    app(BoardCardRegistry::class)->add(EmptyCard::class);
    sweepBoardCards();

    $particles = app(ParticleResourceRegistry::class);
    $provider = app(BoardCardSummaryProvider::class);

    expect($provider->summary($particles->definition('exec-quiet', 'exec')))->toBeNull()
        ->and($provider->summary($particles->definition('exec-dashboard', 'exec')))->toBeNull();
});

it('refuses a card that is no metric, carries no attribute, or names a bad or reserved key', function (string $class, string $message) {
    expect(fn () => app(BoardCardRegistry::class)->add($class))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not a metric' => [stdClass::class, 'must be the class-string of a'],
    'no attribute' => [UndeclaredCard::class, 'carries no #['],
    'not kebab-case' => [BadKeyCard::class, 'declares the key `Revenue_Now`'],
    'the dashboard\'s name' => [DashboardKeyCard::class, 'declares the key `dashboard`'],
]);

it('rejects a second card under a taken key, and a card registered under a key its attribute does not declare', function () {
    $cards = app(BoardCardRegistry::class);

    expect(fn () => $cards->add(DuplicateTenantsCard::class))->toThrow('rejects duplicates')
        ->and($cards->resolve('tenants'))->toBe(TenantsCard::class)
        ->and(fn () => $cards->register('elsewhere', PipelineCard::class))->toThrow(InvalidArgumentException::class, 'declares `pipeline`');
});

it('binds one live source per request scope', function () {
    $first = app(BoardSource::class);

    expect($first)->toBeInstanceOf(LiveBoardSource::class)
        ->and($first->lens())->toBe('live')
        ->and(app(BoardSource::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(BoardSource::class))->not->toBe($first);
});

/*
 * The one rule a card keeps: it reads only through the source. Every registered card is measured against an
 * in-memory source inside DB::listen, and must issue zero queries. A host's own cards are measured the same way
 * once they are registered.
 */
it('measures every registered card with zero queries against an in-memory source', function () {
    // A source with something in every read, so each card walks its whole path rather than its empty branch.
    $source = new InMemoryBoardSource(
        tenants: [boardTenant('a'), boardTenant('b', synthetic: true, created: '2026-08-02'), boardTenant('c', status: 'failed', suspended: true, created: '2026-07-09')],
        subscriptions: [
            new BoardSubscription('a', 'pro', 'Pro', CarbonImmutable::parse('2026-01-01'), commitmentMonths: 12),
            new BoardSubscription('b', 'starter', 'Starter', CarbonImmutable::parse('2026-08-02'), paused: true),
        ],
        usage: [
            new BoardUsage('a', '2026-08', 'openai.tokens', 1.25, 1000),
            new BoardUsage('a', '2026-07', 'openai.tokens', 1.0, 900),
            new BoardUsage('c', '2026-08', 'storage.gb', 0.4, 2),
        ],
        revenue: [
            new PartyPeriodRevenue('a', '2026-07', 'pro', 99.0),
            new PartyPeriodRevenue('a', '2026-08', 'pro', 120.0, usageUsd: 4.5),
            new PartyPeriodRevenue('b', '2026-08', 'starter', 29.0),
        ],
        posture: [PostureSignal::pass('a', 'clean'), PostureSignal::warn('b', 'drift'), PostureSignal::fail('c', 'broken')],
    );
    $cards = app(BoardCardRegistry::class);
    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    foreach ($cards->definitions() as $definition) {
        $figures = $cards->metric($definition)->measure($source);

        expect($figures)->not->toBe([])->each->toBeInstanceOf(BoardFigureData::class);
    }

    expect(count($cards->definitions()))->toBeGreaterThanOrEqual(5)
        ->and($queries)->toBe(0);
});

it('would catch a card that reads around the source (control)', function () {
    $cards = app(BoardCardRegistry::class);
    $cards->add(LeakyCard::class);
    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    foreach ($cards->definitions() as $definition) {
        $cards->metric($definition)->measure(new InMemoryBoardSource);
    }

    expect($queries)->toBe(1);
});
