<?php

require_once __DIR__.'/../Support/ScenarioPlans.php';
require_once __DIR__.'/../Support/Cards.php';

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardCardSummaryProvider;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensMode;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
use Splicewire\Beam\Exec\Board\Sources\SyntheticBoardSource;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Tests\Support\EmptyCard;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;
use Splicewire\Beam\Exec\Tests\Support\ReservedFigureCard;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;

function lensTenant(string $id, bool $synthetic = false): BoardTenant
{
    return new BoardTenant(id: $id, name: ucfirst($id), status: 'active', synthetic: $synthetic, createdAt: CarbonImmutable::parse('2020-01-15'));
}

function lensFor(?string $cookie): BoardLens
{
    $request = Request::create('/exec/dashboard');

    if ($cookie !== null) {
        $request->cookies->set(BoardLens::COOKIE, $cookie);
    }

    return app(BoardLensResolver::class)->resolve($request);
}

/*
 * A probe behind the `web` group, so the lens cookie passes through the host's EncryptCookies as it does on the
 * board's own routes. It answers which source the request's container bound and, for a synthesized one, what
 * every card drew from it and how many queries that took.
 */
function mountLensProbe(): void
{
    Route::middleware('web')->get('/_lens-probe', function () {
        $source = app(BoardSource::class);
        $cards = app(BoardCardRegistry::class);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        // A reading of the estate would need its tables; the probe measures the cards only for a synthesized source.
        $marked = 0;
        foreach ($source->synthesizedFrom() === null ? [] : $cards->definitions() as $definition) {
            $figures = $cards->figures($definition, $source);
            $marked += ($figures[0]->id ?? null) === BoardWatermark::FIGURE ? 1 : 0;
        }

        return ['lens' => $source->lens(), 'class' => class_basename($source), 'chosen' => app(BoardLens::class)->chosen, 'queries' => $queries, 'watermarked' => $marked];
    });
}

it('reads the cookie\'s closed vocabulary, and nothing outside it', function (mixed $value, ?string $parsed) {
    expect(BoardLens::parse($value)?->value())->toBe($parsed);
})->with([
    'live' => ['live', 'live'],
    'truth' => ['truth', 'truth'],
    'scenario' => ['scenario', 'scenario'],
    'a named scenario' => ['scenario:demo-mixed-book', 'scenario:demo-mixed-book'],
    'projection (not yet a lens)' => ['projection', null],
    'a portfolio on live' => ['live:demo-mixed-book', null],
    'a portfolio that is not kebab-case' => ['scenario:Demo Book', null],
    'empty' => ['', null],
    'not a string' => [['live'], null],
    'unknown' => ['everything', null],
]);

it('defaults to Live, and to Truth while the reporting toggle excludes synthetic tenants', function () {
    expect(lensFor(null))->mode->toBe(BoardLensMode::Live)->chosen->toBeFalse();

    config(['beam.tenancy.synthetic.exclude_from_reporting' => true]);

    expect(app(SyntheticReporting::class)->excludes())->toBeTrue();

    expect(lensFor(null))->mode->toBe(BoardLensMode::Truth)->chosen->toBeFalse()
        // The toggle sets the default; it does not forbid choosing Live.
        ->and(lensFor('live'))->mode->toBe(BoardLensMode::Live)->chosen->toBeTrue();
});

it('resolves a Scenario lens to a declared portfolio, or reads the default lens for one the host does not declare', function () {
    expect(lensFor('scenario'))->mode->toBe(BoardLensMode::Scenario)->portfolio->toBe('demo-mixed-book')
        ->and(lensFor('scenario:demo-mixed-book'))->portfolio->toBe('demo-mixed-book')
        ->and(lensFor('scenario:not-declared'))->mode->toBe(BoardLensMode::Live)->chosen->toBeFalse()
        ->and(lensFor('garbage'))->mode->toBe(BoardLensMode::Live);

    config(['beam.exec.scenario_portfolio' => 'not-declared']);

    expect(lensFor('scenario'))->mode->toBe(BoardLensMode::Live);
});

it('builds the source each lens names', function () {
    seedScenarioPlans();
    $resolver = app(BoardLensResolver::class);

    expect($resolver->source(BoardLens::live()))->toBeInstanceOf(LiveBoardSource::class)->lens()->toBe('live')
        ->and($resolver->source(BoardLens::truth()))->toBeInstanceOf(LiveBoardSource::class)->lens()->toBe('truth')
        ->and($resolver->source(BoardLens::scenario('demo-mixed-book')))->toBeInstanceOf(SyntheticBoardSource::class)->lens()->toBe('scenario');
});

it('switches the bound source when the lens cookie changes, through the host\'s cookie encryption', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    seedScenarioPlans();
    mountLensProbe();

    $this->get('/_lens-probe')->assertExactJson(['lens' => 'live', 'class' => 'LiveBoardSource', 'chosen' => false, 'queries' => 0, 'watermarked' => 0]);

    app()->forgetScopedInstances();
    $scenario = $this->withCookie(BoardLens::COOKIE, 'scenario:demo-mixed-book')->get('/_lens-probe');

    // Seven cards, every one measured with zero queries and led by the watermark.
    $scenario->assertExactJson(['lens' => 'scenario', 'class' => 'SyntheticBoardSource', 'chosen' => true, 'queries' => 0, 'watermarked' => 7]);

    app()->forgetScopedInstances();
    $this->withCookie(BoardLens::COOKIE, 'truth')->get('/_lens-probe')->assertJson(['lens' => 'truth', 'class' => 'LiveBoardSource', 'chosen' => true]);

    // A cookie the host did not encrypt is not one the board accepts: EncryptCookies drops it, and the default reads.
    app()->forgetScopedInstances();
    $this->withUnencryptedCookie(BoardLens::COOKIE, 'scenario:demo-mixed-book')->get('/_lens-probe')->assertJson(['lens' => 'live', 'chosen' => false]);
});

it('issues the cookie that chooses a lens, and the one that forgets it', function () {
    $resolver = app(BoardLensResolver::class);
    $cookie = $resolver->cookie(BoardLens::scenario('demo-mixed-book'));

    expect($cookie->getName())->toBe('beam_exec_lens')
        ->and($cookie->getValue())->toBe('scenario:demo-mixed-book')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime() - time())->toBeGreaterThan(479 * 60)->toBeLessThanOrEqual(480 * 60)
        ->and(BoardLens::parse($cookie->getValue()))->mode->toBe(BoardLensMode::Scenario)
        ->and($resolver->forget()->getExpiresTime())->toBeLessThan(time());
});

it('stamps the watermark on every figure of a synthesized source, on the card\'s page and on its dashboard summary', function () {
    app()->instance(BoardSource::class, new InMemoryBoardSource(
        tenants: [lensTenant('a'), lensTenant('b', synthetic: true)],
        synthesizedFrom: 'Board pack Q3',
    ));

    $particles = app(ParticleResourceRegistry::class);
    $rows = $particles->get('exec-tenants')->backing->records([], null, 1)->items();
    $summary = app(BoardCardSummaryProvider::class)->summary($particles->definition('exec-tenants', 'exec'));

    expect(array_map(fn (BoardFigureData $f): array => [$f->id, $f->value, $f->synthesized], $rows))->toBe([
        ['synthesized', 'Board pack Q3', true],
        ['total', 2, true],
        ['active', 2, true],
        ['new', 0, true],
        ['synthetic', 1, true],
    ])
        ->and($rows[0]->label)->toBe(BoardWatermark::TEXT)
        ->and($rows[0]->tone)->toBe('warn')
        // Frame's summary figure has no flag; the watermark's own figure is what carries the mark there.
        ->and($summary->figures[0]->key)->toBe('synthesized')
        ->and($summary->figures[0]->label)->toBe('Synthesized — not real data')
        ->and(count($summary->figures))->toBe(5);
});

it('never stamps a reading of the estate, leaves an empty card empty, and refuses a card that takes the watermark\'s id', function () {
    $cards = app(BoardCardRegistry::class);
    $tenants = $cards->definitions()[0];

    expect(array_column(array_map(fn ($f) => (array) $f, $cards->figures($tenants, new InMemoryBoardSource([lensTenant('a')]))), 'synthesized'))
        ->toBe([false, false, false, false]);

    $cards->add(EmptyCard::class);
    $cards->add(ReservedFigureCard::class);
    $synthesized = new InMemoryBoardSource(synthesizedFrom: 'X');

    expect($cards->figures($cards->definitions()[array_search('quiet', $cards->cardKeys(), true)], $synthesized))->toBe([])
        ->and(fn () => $cards->figures($cards->definitions()[array_search('reserved', $cards->cardKeys(), true)], new InMemoryBoardSource))
        ->toThrow(LogicException::class, 'The board card `reserved` emits the figure id `synthesized`');
});
