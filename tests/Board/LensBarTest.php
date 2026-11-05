<?php

require_once __DIR__.'/../Support/ScenarioPlans.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Splicewire\Beam\Exec\Board\BoardCardDefinition;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensBar;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Data\BoardLensBarData;
use Splicewire\Beam\Exec\Data\BoardLensChoiceData;
use Splicewire\Beam\Exec\Ops\ReadBoardLens;
use Splicewire\Beam\Exec\Ops\SwitchBoardLens;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Tenancy\Tenant;

/*
 * The lens bar's two declared operations, mounted the way a host mounts them (inside a `web` group, so the lens
 * cookie passes through the host's EncryptCookies), over a central schema with no rows: a Live reading, a Scenario
 * priced by the two Demo plans, and a Projection all build against it.
 */
beforeEach(function () {
    config([
        'tenancy.tenant_model' => Tenant::class,
        'tenancy.database.central_connection' => config('database.default'),
        'auth.providers.users.model' => LensBarUser::class,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
    ]);

    Schema::create('users', function (Blueprint $t): void {
        $t->string('id')->primary();
        $t->string('email');
        $t->timestamps();
    });
    Schema::create('sessions', function (Blueprint $t): void {
        $t->string('id')->primary();
    });
    Schema::create('tenants', function (Blueprint $t): void {
        $t->string('id')->primary();
        $t->string('name')->nullable();
        $t->string('slug')->nullable();
        $t->string('parent_tenant_id')->nullable();
        $t->json('data')->nullable();
        $t->timestamps();
    });
    Schema::create('subscriptions', function (Blueprint $t): void {
        $t->uuid('id')->primary();
        $t->string('party_id');
        $t->string('party_type');
        $t->uuid('plan_id')->nullable();
        $t->string('subscribable_type')->nullable();
        $t->string('subscribable_id')->nullable();
        $t->integer('commitment_months')->default(1);
        $t->json('overrides')->nullable();
        $t->timestamp('started_at');
        $t->timestamp('ended_at')->nullable();
        $t->timestamp('paused_at')->nullable();
        $t->timestamps();
    });
    Schema::create('central_usage_summaries', function (Blueprint $t): void {
        $t->uuid('id')->primary();
        $t->string('month');
        $t->decimal('total_cost_usd', 12, 6)->default(0);
        $t->unsignedBigInteger('total_tokens')->default(0);
        $t->json('models')->nullable();
        $t->json('meters')->nullable();
        $t->timestamps();
    });
    Schema::create('tenant_usage_summaries', function (Blueprint $t): void {
        $t->string('tenant_id');
        $t->uuid('usage_summary_id');
        $t->timestamps();
    });
    seedScenarioPlans();

    Route::middleware('web')->group(function (): void {
        Particle::ops('exec', 'exec-dashboard', [ReadBoardLens::class, SwitchBoardLens::class]);
    });
    app('router')->getRoutes()->refreshNameLookups();

    Gate::define('entitlement:exec.view', fn (?LensBarUser $user): bool => $user?->email === 'board@beam.test');

    $this->board = LensBarUser::create(['id' => (string) Str::uuid(), 'email' => 'board@beam.test']);
    $this->member = LensBarUser::create(['id' => (string) Str::uuid(), 'email' => 'member@beam.test']);
});

/** A fresh request scope, as a real host request is (the test app keeps scoped instances between calls). */
function nextLensRequest(): void
{
    app()->forgetScopedInstances();
}

function cacheControl(TestResponse $response): string
{
    return (string) $response->headers->get('Cache-Control');
}

it('declares both lens operations, subject-free and gated by exec.view on the entitlement plane, with every shape slot', function () {
    $ops = app(ParticleOperationRegistry::class);
    $read = $ops->get('exec-dashboard', 'lens');
    $switch = $ops->get('exec-dashboard', 'switch-lens');

    expect([$read->kind, $read->method, $read->ability, $read->abilityModel, $read->input, $read->output, $read->subject])
        ->toBe([OperationKind::Read, HttpMethod::Get, 'exec.view', false, false, BoardLensBarData::class, NoSubject::class])
        ->and([$switch->kind, $switch->ability, $switch->abilityModel, $switch->input, $switch->output, $switch->subject])
        ->toBe([OperationKind::Write, 'exec.view', false, BoardLensChoiceData::class, BoardLensBarData::class, NoSubject::class])
        ->and(Route::has(BoardLensBar::SWITCH_ROUTE))->toBeTrue()
        ->and(route(BoardLensBar::SWITCH_ROUTE, [], false))->toBe('/exec/switch-lens');
});

it('refuses both operations to a user who is not on the board, and sets no cookie', function () {
    $this->actingAs($this->member)->getJson('/exec/lens')->assertForbidden();

    nextLensRequest();
    $refused = $this->actingAs($this->member)->postJson('/exec/switch-lens', ['lens' => 'scenario']);

    $refused->assertForbidden()->assertCookieMissing(BoardLens::COOKIE);
});

it('sets the encrypted lens cookie and answers the bar the next request will read, no-store', function () {
    $response = $this->actingAs($this->board)->postJson('/exec/switch-lens', ['lens' => 'scenario:demo-mixed-book']);

    $response->assertOk()
        // assertCookie decrypts: the cookie went out through EncryptCookies.
        ->assertCookie(BoardLens::COOKIE, 'scenario:demo-mixed-book')
        ->assertJsonPath('data.lens', 'scenario:demo-mixed-book')
        ->assertJsonPath('data.mode', 'scenario')
        ->assertJsonPath('data.chosen', true)
        ->assertJsonPath('data.banner.kind', 'synthesized')
        ->assertJsonPath('data.banner.label', BoardWatermark::TEXT)
        ->assertJsonPath('data.banner.detail', 'Demo mixed book')
        ->assertJsonPath('data.switchUrl', '/exec/switch-lens');

    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === BoardLens::COOKIE);

    expect($cookie->getValue())->not->toContain('scenario')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(cacheControl($response))->toContain('no-store');

    // Live clears the banner; the response still sets the lens, so it is still no-store.
    nextLensRequest();
    $live = $this->actingAs($this->board)->postJson('/exec/switch-lens', ['lens' => 'live']);

    $live->assertOk()->assertCookie(BoardLens::COOKIE, 'live')->assertJsonPath('data.banner', null)->assertJsonPath('data.mode', 'live');
    expect(cacheControl($live))->toContain('no-store');
});

it('refuses a lens the board would not honour, with a 422 and no cookie', function (mixed $lens) {
    $response = $this->actingAs($this->board)->postJson('/exec/switch-lens', $lens === null ? [] : ['lens' => $lens]);

    $response->assertUnprocessable()->assertJsonValidationErrors('lens')->assertCookieMissing(BoardLens::COOKIE);
})->with([
    'no lens' => [null],
    'empty' => [''],
    'an unknown mode' => ['forecast'],
    'a mode in the wrong case' => ['Live'],
    'a portfolio on a mode that takes none' => ['truth:demo-mixed-book'],
    'an undeclared portfolio' => ['scenario:no-such-book'],
    'an undeclared projection portfolio' => ['projection:no-such-book'],
    'not a string' => [['scenario']],
]);

it('reads the bar: four segments, the active one marked, and a banner only under Scenario and Projection', function () {
    $live = $this->actingAs($this->board)->getJson('/exec/lens');

    $live->assertOk()
        ->assertJsonPath('data.mode', 'live')
        ->assertJsonPath('data.chosen', false)
        ->assertJsonPath('data.defaultMode', 'live')
        ->assertJsonPath('data.banner', null);
    expect(collect($live->json('data.options'))->map(fn (array $o): array => [$o['value'], $o['label'], $o['active'], $o['available']])->all())->toBe([
        ['live', 'Live', true, true],
        ['truth', 'Truth', false, true],
        ['scenario', 'Scenario', false, true],
        ['projection', 'Projection', false, true],
    ])
        ->and(cacheControl($live))->not->toContain('no-store');

    nextLensRequest();
    $scenario = $this->actingAs($this->board)->withCredentials()->withCookie(BoardLens::COOKIE, 'scenario:demo-mixed-book')->getJson('/exec/lens');
    $scenario->assertOk()->assertJsonPath('data.mode', 'scenario')->assertJsonPath('data.banner.kind', 'synthesized');
    expect(collect($scenario->json('data.options'))->firstWhere('active', true)['value'])->toBe('scenario')
        ->and(cacheControl($scenario))->toContain('no-store');

    nextLensRequest();
    $projection = $this->actingAs($this->board)->withCredentials()->withCookie(BoardLens::COOKIE, 'projection')->getJson('/exec/lens');
    $projection->assertOk()
        ->assertJsonPath('data.mode', 'projection')
        ->assertJsonPath('data.banner.kind', 'projected')
        ->assertJsonPath('data.banner.label', BoardWatermark::PROJECTED_TEXT);
    expect($projection->json('data.banner.detail'))->toMatch('/^Live, \w+ \d{4} held flat, 6 months ahead$/')
        ->and(cacheControl($projection))->toContain('no-store');
});

it('takes the banner from the source the cards read, not from the cookie', function () {
    // A cookie naming a scenario this host does not declare reads the default lens: no card is watermarked, no banner.
    $stale = $this->actingAs($this->board)->withCredentials()->withCookie(BoardLens::COOKIE, 'scenario:no-such-book')->getJson('/exec/lens');
    $stale->assertOk()->assertJsonPath('data.mode', 'live')->assertJsonPath('data.banner', null);

    // A source that synthesizes shows the banner with no cookie at all.
    nextLensRequest();
    app()->instance(BoardSource::class, new InMemoryBoardSource(synthesizedFrom: 'Board pack Q3'));
    $synth = $this->actingAs($this->board)->getJson('/exec/lens');

    $synth->assertOk()->assertJsonPath('data.mode', 'live')->assertJsonPath('data.banner.kind', 'synthesized')->assertJsonPath('data.banner.detail', 'Board pack Q3');
    expect(cacheControl($synth))->toContain('no-store');
});

it('answers a card summary no-store when its figures are synthesized or projected, and leaves a live one cacheable', function () {
    $live = $this->actingAs($this->board)->getJson('/frame/resources/exec-revenue/summary');
    $live->assertOk();
    expect(cacheControl($live))->not->toContain('no-store');

    nextLensRequest();
    $scenario = $this->actingAs($this->board)->withCredentials()->withCookie(BoardLens::COOKIE, 'scenario:demo-mixed-book')->getJson('/frame/resources/exec-revenue/summary');
    $scenario->assertOk();
    expect($scenario->json('figures.0.key') ?? $scenario->json('data.figures.0.key'))->toBe(BoardWatermark::FIGURE)
        ->and(cacheControl($scenario))->toContain('no-store');

    nextLensRequest();
    $projection = $this->actingAs($this->board)->withCredentials()->withCookie(BoardLens::COOKIE, 'projection')->getJson('/frame/resources/exec-revenue/summary');
    $projection->assertOk();
    expect($projection->json('figures.0.key') ?? $projection->json('data.figures.0.key'))->toBe(BoardWatermark::PROJECTED_FIGURE)
        ->and(cacheControl($projection))->toContain('no-store');
});

it('chooses only what it would honour: choose() is resolve() without the fallback', function () {
    $resolver = app(BoardLensResolver::class);

    expect($resolver->choose('scenario')?->value())->toBe('scenario:demo-mixed-book')
        ->and($resolver->choose('projection')?->value())->toBe('projection')
        ->and($resolver->choose('projection:demo-mixed-book')?->value())->toBe('projection:demo-mixed-book')
        ->and($resolver->choose('scenario:no-such-book'))->toBeNull()
        ->and($resolver->choose('nonsense'))->toBeNull();
});

class LensBarUser extends AuthUser
{
    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}

it('gives every card page its columns: the manifest a card page draws from carries label, value and forecast as list columns', function () {
    $manifest = $this->actingAs($this->board)->getJson('/frame/manifest');
    $manifest->assertOk();

    $cards = collect(app(BoardCardRegistry::class)->definitions())->map(fn (BoardCardDefinition $card): string => $card->resourceKey())->values();
    expect($cards)->toContain('exec-revenue', 'exec-mrr-trend', 'exec-posture')
        ->and(collect($manifest->json('resources'))->pluck('key')->intersect($cards)->count())->toBe($cards->count());

    foreach ($cards as $key) {
        $byNode = $manifest->json("contexts.{$key}.byNode") ?? [];
        $columns = collect($byNode)
            ->filter(fn (array $contexts, string $node): bool => $node !== '' && ($contexts['list-column']['participates'] ?? false))
            ->sortBy(fn (array $contexts): int => $contexts['list-column']['sort'] ?? PHP_INT_MAX)
            ->map(fn (array $contexts): ?string => $contexts['list-column']['label'] ?? null);

        expect($columns->all())->toBe(['label' => 'Figure', 'value' => 'Value', 'forecast' => 'Forecast'], "{$key} draws no columns");
    }

    // The rows the page draws carry every column's cell.
    nextLensRequest();
    $rows = $this->actingAs($this->board)->getJson('/frame/resources/exec-revenue');
    $rows->assertOk();
    $first = $rows->json('data.0');
    expect($first)->toHaveKeys(['id', 'label', 'value', 'forecast']);
});
