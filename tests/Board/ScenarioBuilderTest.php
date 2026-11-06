<?php

require_once __DIR__.'/../Support/ScenarioPlans.php';

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Exec\Board\BoardCardDefinition;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Board\Cards\MrrTrendCard;
use Splicewire\Beam\Exec\Board\Cards\RevenueCard;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Board\Scenarios\ScenarioBuilder;
use Splicewire\Beam\Exec\Data\ScenarioCatalogData;
use Splicewire\Beam\Exec\Data\ScenarioDraftData;
use Splicewire\Beam\Exec\Data\ScenarioIdData;
use Splicewire\Beam\Exec\Data\ScenarioPreviewData;
use Splicewire\Beam\Exec\Data\ScenarioSaveData;
use Splicewire\Beam\Exec\Ops\DeleteScenario;
use Splicewire\Beam\Exec\Ops\PreviewScenario;
use Splicewire\Beam\Exec\Ops\ReadBoardLens;
use Splicewire\Beam\Exec\Ops\ReadScenarios;
use Splicewire\Beam\Exec\Ops\SaveScenario;
use Splicewire\Beam\Exec\Ops\SwitchBoardLens;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Store\StoredPortfolio;
use Splicewire\Beam\Synthetic\Store\StoredPortfolios;
use Splicewire\Beam\Tenancy\Tenant;

/*
 * The scenario builder's four declared operations, mounted the way a host mounts them (inside `web`, beside the lens
 * operations), over a central schema with the two Demo plans and beam-synthetic's stored-portfolio table migrated from
 * its real stub. Three readers: an operator (exec.view and exec.scenario.manage), a board seat (exec.view only, the
 * read seat), and a team member (neither).
 */
beforeEach(function () {
    config([
        'tenancy.tenant_model' => Tenant::class,
        'tenancy.database.central_connection' => config('database.default'),
        'auth.providers.users.model' => ScenarioBuilderUser::class,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
    ]);

    Schema::create('users', function (Blueprint $t): void {
        $t->string('id')->primary();
        $t->string('email');
        $t->timestamps();
    });
    Schema::create('activity_log', function (Blueprint $t): void {
        $t->id();
        $t->string('log_name')->nullable();
        $t->text('description');
        $t->string('subject_type')->nullable();
        $t->string('subject_id')->nullable();
        $t->string('event')->nullable();
        $t->string('causer_type')->nullable();
        $t->string('causer_id')->nullable();
        $t->json('properties')->nullable();
        $t->json('attribute_changes')->nullable();
        $t->uuid('batch_uuid')->nullable();
        $t->timestamps();
    });
    (require dirname(__DIR__, 2).'/vendor/splicewire/laravel-beam-synthetic/database/migrations/create_beam_synthetic_portfolios_table.php.stub')->up();
    seedScenarioPlans();

    Route::middleware('web')->group(function (): void {
        Particle::ops('exec', 'exec-dashboard', [
            ReadBoardLens::class, SwitchBoardLens::class,
            ReadScenarios::class, PreviewScenario::class, SaveScenario::class, DeleteScenario::class,
        ]);
    });
    app('router')->getRoutes()->refreshNameLookups();

    Gate::define('entitlement:exec.view', fn (?ScenarioBuilderUser $user): bool => in_array($user?->email, ['operator@beam.test', 'seat@beam.test'], true));
    Gate::define('entitlement:exec.scenario.manage', fn (?ScenarioBuilderUser $user): bool => in_array($user?->email, ['operator@beam.test', 'manage-only@beam.test'], true));

    $this->operator = ScenarioBuilderUser::create(['id' => (string) Str::uuid(), 'email' => 'operator@beam.test']);
    $this->seat = ScenarioBuilderUser::create(['id' => (string) Str::uuid(), 'email' => 'seat@beam.test']);
    $this->member = ScenarioBuilderUser::create(['id' => (string) Str::uuid(), 'email' => 'member@beam.test']);
});

class ScenarioBuilderUser extends AuthUser
{
    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}

/** A fresh request scope, as a real host request is. */
function nextScenarioRequest(): void
{
    app()->forgetScopedInstances();
}

/** A two-member draft over the Demo blueprints. */
function upsideDraft(array $overrides = []): array
{
    return [
        'label' => 'Board upside',
        'members' => [
            ['blueprint' => 'demo-quillmere-studio', 'count' => 6, 'startOffsetMonths' => 0],
            ['blueprint' => 'demo-orrowby-hart', 'count' => 2, 'startOffsetMonths' => 4],
        ],
        'churnRate' => 0.02,
        ...$overrides,
    ];
}

it('declares all four scenario operations, subject-free, with every shape slot and the two gates', function () {
    $ops = app(ParticleOperationRegistry::class);
    $slots = fn (string $name): array => (fn ($op) => [$op->kind, $op->method, $op->ability, $op->abilityModel, $op->input, $op->output, $op->subject, $op->affordance])($ops->get('exec-dashboard', $name));

    expect($slots('scenarios'))->toBe([OperationKind::Read, HttpMethod::Get, 'exec.view', false, false, ScenarioCatalogData::class, NoSubject::class, false])
        ->and($slots('preview-scenario'))->toBe([OperationKind::Read, null, 'exec.view', false, ScenarioDraftData::class, ScenarioPreviewData::class, NoSubject::class, false])
        ->and($slots('save-scenario'))->toBe([OperationKind::Write, null, 'exec.scenario.manage', false, ScenarioSaveData::class, ScenarioCatalogData::class, NoSubject::class, false])
        ->and($slots('delete-scenario'))->toBe([OperationKind::Write, null, 'exec.scenario.manage', false, ScenarioIdData::class, ScenarioCatalogData::class, NoSubject::class, false])
        ->and(collect(['scenarios', 'preview-scenario', 'save-scenario', 'delete-scenario'])->map(fn (string $op): string => route("exec-dashboard.{$op}", [], false))->all())
        ->toBe(['/exec/scenarios', '/exec/preview-scenario', '/exec/save-scenario', '/exec/delete-scenario'])
        ->and(config('beam.core.entitlements.keys'))->toContain('exec.view', 'exec.scenario.manage');
});

it('describes every property of every exec operation\'s declared input, so a host\'s reference and SDK can say what each takes', function () {
    // A host publishes an op's `input:` property by property; a property with no description is a gap there (the
    // flagship gates it: ParticleOperationInputCoverageTest). Walk every exec-dashboard op, inherited properties
    // included (ScenarioSaveData inherits the draft's), and read the attribute the schema reads.
    $inputs = collect(app(ParticleOperationRegistry::class)->all())
        ->filter(fn ($op): bool => $op->resource === 'exec-dashboard' && is_string($op->input))
        ->mapWithKeys(fn ($op): array => [$op->name => $op->input]);

    expect($inputs->keys()->sort()->values()->all())
        ->toBe(['delete-scenario', 'preview-scenario', 'save-scenario', 'switch-lens']);

    $undescribed = [];
    foreach ($inputs as $name => $input) {
        foreach ((new ReflectionClass($input))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $description = $property->getAttributes(Description::class)[0] ?? null;
            if (trim((string) ($description?->newInstance()->value ?? '')) === '') {
                $undescribed[] = "{$name} {$property->getName()} (".class_basename($input).')';
            }
        }
    }

    expect($undescribed)->toBe([]);
});

it('lists the host\'s portfolio files and the blueprints to compose from, and says who may save', function () {
    $read = $this->actingAs($this->operator)->getJson('/exec/scenarios');

    $read->assertOk()
        ->assertJsonPath('data.scenarios.0.id', 'demo-mixed-book')
        ->assertJsonPath('data.scenarios.0.source', 'file')
        ->assertJsonPath('data.scenarios.0.lens', 'scenario:demo-mixed-book')
        ->assertJsonPath('data.canManage', true)
        ->assertJsonPath('data.storeAvailable', true)
        ->assertJsonPath('data.previewUrl', '/exec/preview-scenario')
        ->assertJsonPath('data.saveUrl', '/exec/save-scenario')
        ->assertJsonPath('data.deleteUrl', '/exec/delete-scenario')
        ->assertJsonPath('data.switchUrl', '/exec/switch-lens');
    expect(collect($read->json('data.blueprints'))->pluck('id')->all())->toBe(['demo-orrowby-hart', 'demo-quillmere-studio', 'demo-tessaline-works'])
        ->and(array_keys($read->json('data.blueprints.0')))->toBe(['id', 'name', 'planSlug', 'scenario', 'windowMonths'])
        ->and(array_keys($read->json('data.scenarios.0')))->toBe(['id', 'label', 'lens', 'source', 'members', 'churnRate', 'expansionRate', 'active']);

    nextScenarioRequest();
    $this->actingAs($this->seat)->getJson('/exec/scenarios')->assertOk()->assertJsonPath('data.canManage', false);
});

it('refuses every scenario operation to a user who is not on the board, storing nothing', function () {
    $this->actingAs($this->member)->getJson('/exec/scenarios')->assertForbidden();
    nextScenarioRequest();
    $this->actingAs($this->member)->postJson('/exec/preview-scenario', upsideDraft())->assertForbidden();
    nextScenarioRequest();
    $this->actingAs($this->member)->postJson('/exec/save-scenario', [...upsideDraft(), 'id' => 'board-upside'])->assertForbidden();
    nextScenarioRequest();
    $this->actingAs($this->member)->postJson('/exec/delete-scenario', ['id' => 'board-upside'])->assertForbidden();

    expect(StoredPortfolio::query()->count())->toBe(0);
});

it('lets a board seat preview but not save or delete: the seat stays a read seat', function () {
    $this->actingAs($this->seat)->postJson('/exec/preview-scenario', upsideDraft())->assertOk();
    nextScenarioRequest();
    $this->actingAs($this->seat)->postJson('/exec/save-scenario', [...upsideDraft(), 'id' => 'board-upside'])->assertForbidden();
    nextScenarioRequest();
    $this->actingAs($this->seat)->postJson('/exec/delete-scenario', ['id' => 'board-upside'])->assertForbidden();

    expect(StoredPortfolio::query()->count())->toBe(0);
});

it('requires a board seat as well as exec.scenario.manage to save', function () {
    $manageOnly = ScenarioBuilderUser::create(['id' => (string) Str::uuid(), 'email' => 'manage-only@beam.test']);

    $this->actingAs($manageOnly)->postJson('/exec/save-scenario', [...upsideDraft(), 'id' => 'board-upside'])->assertForbidden();

    expect(StoredPortfolio::query()->count())->toBe(0);
});

it('previews a draft in memory: the one query is the plans read, then the figures cost nothing', function () {
    $draft = ScenarioDraftData::from(upsideDraft());
    $builder = app(ScenarioBuilder::class);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $preview = $builder->preview($draft, CarbonImmutable::parse('2026-08-20 12:00:00'));

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('"plans"')
        ->and($preview->tenants)->toBe(8)
        ->and($preview->asOf)->toBe('2026-08')
        ->and($preview->months)->toBe(16)
        // Every figure leads with the synthesized watermark, valued with the draft's label.
        ->and([$preview->revenue[0]->key, $preview->revenue[0]->label, $preview->revenue[0]->value])->toBe([BoardWatermark::FIGURE, BoardWatermark::TEXT, 'Board upside'])
        ->and($preview->trend[0]->key)->toBe(BoardWatermark::FIGURE)
        ->and(collect($preview->trend)->skip(1)->pluck('key')->first())->toBe('2025-08')
        ->and(collect($preview->trend)->last()->key)->toBe('2026-08')
        ->and(collect($preview->trend)->last()->value)->toBeGreaterThan(0);
});

it('would see a query if the preview made a second one, so the count above is not a deaf listener', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    app(ScenarioBuilder::class)->preview(ScenarioDraftData::from(upsideDraft()));
    DB::table('plans')->count();

    expect($queries)->toBe(2);
});

it('previews the same figures the Scenario lens shows once the draft is saved (a second instrument)', function () {
    $asOf = CarbonImmutable::parse('2026-08-20 12:00:00');
    $preview = app(ScenarioBuilder::class)->preview(ScenarioDraftData::from(upsideDraft()), $asOf);

    app(StoredPortfolios::class)->save(ScenarioSaveData::from([...upsideDraft(), 'id' => 'board-upside'])->toPortfolio('board-upside'));
    nextScenarioRequest();

    $source = app(BoardLensResolver::class)->source(BoardLens::scenario('board-upside'), $asOf);
    $registry = app(BoardCardRegistry::class);
    $figures = fn (string $key): array => array_map(fn ($f) => $f->toSummaryFigure()->toArray(), $registry->figures(BoardCardDefinition::of($key === 'revenue' ? RevenueCard::class : MrrTrendCard::class), $source));

    expect(array_map(fn ($f) => $f->toArray(), $preview->revenue))->toBe($figures('revenue'))
        ->and(array_map(fn ($f) => $f->toArray(), $preview->trend))->toBe($figures('mrr-trend'));
});

it('answers the preview over HTTP no-store, with no query beyond the session and the plans read', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->actingAs($this->operator)->postJson('/exec/preview-scenario', upsideDraft());

    $response->assertOk()
        ->assertJsonPath('data.label', 'Board upside')
        ->assertJsonPath('data.tenants', 8)
        ->assertJsonPath('data.revenue.0.key', 'synthesized')
        ->assertJsonPath('data.trend.0.key', 'synthesized');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(collect($queries)->reject(fn (string $sql): bool => str_contains($sql, '"sessions"'))->values()->all())
        ->toHaveCount(1)
        ->and(collect($queries)->first(fn (string $sql): bool => ! str_contains($sql, '"sessions"')))->toContain('"plans"')
        ->and(StoredPortfolio::query()->count())->toBe(0);
});

it('refuses a draft it cannot preview, with a 422 on the field', function (array $draft, string $field) {
    $this->actingAs($this->operator)->postJson('/exec/preview-scenario', $draft)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no members' => [upsideDraft(['members' => []]), 'members'],
    'an unknown blueprint' => [upsideDraft(['members' => [['blueprint' => 'no-such-blueprint']]]), 'members.0.blueprint'],
    'a count of 0' => [upsideDraft(['members' => [['blueprint' => 'demo-orrowby-hart', 'count' => 0]]]), 'members.0.count'],
    'a count over the cap' => [upsideDraft(['members' => [['blueprint' => 'demo-orrowby-hart', 'count' => ScenarioDraftData::MAX_COUNT + 1]]]), 'members.0.count'],
    'a negative offset' => [upsideDraft(['members' => [['blueprint' => 'demo-orrowby-hart', 'startOffsetMonths' => -1]]]), 'members.0.startOffsetMonths'],
    'a rate over 1' => [upsideDraft(['churnRate' => 1.5]), 'churnRate'],
    'no label' => [upsideDraft(['label' => '']), 'label'],
]);

it('refuses to price a scenario on a plan the host does not sell, naming it', function () {
    DB::table('plans')->where('slug', 'starter')->delete();

    $response = $this->actingAs($this->operator)->postJson('/exec/preview-scenario', upsideDraft(['members' => [['blueprint' => 'demo-tessaline-works', 'count' => 2]]]));

    $response->assertUnprocessable()->assertJsonValidationErrors('members');
    expect($response->json('errors.members.0'))->toContain('starter');
});

it('saves a scenario that is a Scenario lens from the next request, logged to the central activity trail', function () {
    $saved = $this->actingAs($this->operator)->postJson('/exec/save-scenario', [...upsideDraft(), 'id' => 'board-upside']);

    $saved->assertOk();
    expect(collect($saved->json('data.scenarios'))->map(fn (array $s): array => [$s['id'], $s['source']])->all())
        ->toBe([['demo-mixed-book', 'file'], ['board-upside', 'stored']])
        ->and(StoredPortfolio::query()->find('board-upside')?->label)->toBe('Board upside');

    $log = CentralActivityLog::query()->where('log_name', StoredPortfolios::LOG_NAME)->sole();
    expect([$log->event, $log->subject_id, $log->causer_id])->toBe(['created', 'board-upside', $this->operator->id]);

    // Selectable at once: the bar lists it, the switch accepts it, and the board then reads it under the banner.
    nextScenarioRequest();
    $bar = $this->actingAs($this->operator)->getJson('/exec/lens');
    expect(collect($bar->json('data.scenarios'))->pluck('value')->all())->toBe(['scenario:demo-mixed-book', 'scenario:board-upside']);

    nextScenarioRequest();
    $this->actingAs($this->operator)->postJson('/exec/switch-lens', ['lens' => 'scenario:board-upside'])
        ->assertOk()
        ->assertCookie(BoardLens::COOKIE, 'scenario:board-upside')
        ->assertJsonPath('data.banner.kind', 'synthesized')
        ->assertJsonPath('data.banner.detail', 'Board upside');

    nextScenarioRequest();
    $active = $this->actingAs($this->operator)->withCredentials()->withCookie(BoardLens::COOKIE, 'scenario:board-upside')->getJson('/exec/scenarios');
    expect(collect($active->json('data.scenarios'))->firstWhere('active', true)['id'])->toBe('board-upside');
});

it('refuses to save under a file\'s id, an id that is not kebab-case, or an unknown blueprint, storing nothing', function (array $body, string $field) {
    $this->actingAs($this->operator)->postJson('/exec/save-scenario', $body)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(StoredPortfolio::query()->count())->toBe(0)
        ->and(CentralActivityLog::query()->count())->toBe(0);
})->with([
    'the Demo file\'s id' => [[...upsideDraft(), 'id' => 'demo-mixed-book'], 'id'],
    'a capitalised id' => [[...upsideDraft(), 'id' => 'Board-Upside'], 'id'],
    'a lens value as the id' => [[...upsideDraft(), 'id' => 'scenario:board-upside'], 'id'],
    'no id' => [upsideDraft(), 'id'],
    'an unknown blueprint' => [[...upsideDraft(['members' => [['blueprint' => 'no-such-blueprint']]]), 'id' => 'board-upside'], 'members.0.blueprint'],
]);

it('replaces a stored scenario saved again under its id', function () {
    $this->actingAs($this->operator)->postJson('/exec/save-scenario', [...upsideDraft(), 'id' => 'board-upside'])->assertOk();
    nextScenarioRequest();
    $this->actingAs($this->operator)->postJson('/exec/save-scenario', [...upsideDraft(['label' => 'Board upside, revised']), 'id' => 'board-upside'])->assertOk();

    expect(StoredPortfolio::query()->count())->toBe(1)
        ->and(app(PortfolioRegistry::class)->get('board-upside')?->label)->toBe('Board upside, revised')
        ->and(CentralActivityLog::query()->orderBy('id')->pluck('event')->all())->toBe(['created', 'updated']);
});

it('deletes a stored scenario, refuses a file or an unknown id, and a lens left on the deleted one reads the default', function () {
    $this->actingAs($this->operator)->postJson('/exec/save-scenario', [...upsideDraft(), 'id' => 'board-upside'])->assertOk();

    nextScenarioRequest();
    $this->actingAs($this->operator)->postJson('/exec/delete-scenario', ['id' => 'demo-mixed-book'])->assertUnprocessable()->assertJsonValidationErrors('id');
    nextScenarioRequest();
    $this->actingAs($this->operator)->postJson('/exec/delete-scenario', ['id' => 'never-saved'])->assertUnprocessable()->assertJsonValidationErrors('id');

    nextScenarioRequest();
    $deleted = $this->actingAs($this->operator)->postJson('/exec/delete-scenario', ['id' => 'board-upside']);
    $deleted->assertOk();
    expect(collect($deleted->json('data.scenarios'))->pluck('id')->all())->toBe(['demo-mixed-book'])
        ->and(StoredPortfolio::query()->count())->toBe(0)
        ->and(CentralActivityLog::query()->orderBy('id')->pluck('event')->all())->toBe(['created', 'deleted']);

    nextScenarioRequest();
    $this->actingAs($this->operator)->withCredentials()->withCookie(BoardLens::COOKIE, 'scenario:board-upside')->getJson('/exec/lens')
        ->assertOk()
        ->assertJsonPath('data.mode', 'live')
        ->assertJsonPath('data.banner', null);
});
