<?php

require_once __DIR__.'/../Support/ScenarioPlans.php';

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardFacetOptions;
use Splicewire\Beam\Exec\Board\BoardProjection;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Board\Cards\MrrTrendCard;
use Splicewire\Beam\Exec\Board\Cards\PostureCard;
use Splicewire\Beam\Exec\Board\Scenarios\ScenarioBuilder;
use Splicewire\Beam\Exec\Data\ScenarioDraftData;
use Splicewire\Beam\Exec\Data\ScenarioSaveData;
use Splicewire\Beam\Exec\Localization\ExecAccessDeniedException;
use Splicewire\Beam\Exec\Localization\ExecError;
use Splicewire\Beam\Exec\Localization\ExecValidationException;
use Splicewire\Beam\Exec\Ops\DeleteScenario;
use Splicewire\Beam\Exec\Ops\PreviewScenario;
use Splicewire\Beam\Exec\Ops\ReadBoardLens;
use Splicewire\Beam\Exec\Ops\ReadScenarios;
use Splicewire\Beam\Exec\Ops\SaveScenario;
use Splicewire\Beam\Exec\Ops\SwitchBoardLens;
use Splicewire\Beam\Exec\Posture\PostureSignal;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Tenancy\Tenant;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/*
 * The scenario builder's four declared operations, mounted the way a host mounts them (inside `web`, beside the lens
 * operations), over a central schema with the two Demo plans and beam-synthetic's central Data-declared particles, with no dedicated portfolio table. Three readers: an operator (exec.view and exec.scenario.manage), a board seat (exec.view only, the
 * read seat), and a team member (neither).
 */
beforeEach(function () {
    config(['app.debug' => false]);
    CarbonImmutable::setTestNow('2026-08-20 12:00:00');
    Carbon::setTestNow('2026-08-20 12:00:00');
    app()->setLocale('en');
    config([
        'tenancy.tenant_model' => Tenant::class,
        'tenancy.database.central_connection' => config('database.default'),
        'auth.providers.users.model' => Localization25User::class,
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
    (require dirname(__DIR__, 2).'/vendor/splicewire/laravel-beam/database/migrations/shared/create_beam_particles_table.php.stub')->up();
    expect(Schema::hasTable('beam_synthetic_portfolios'))->toBeFalse();
    seedScenarioPlans();

    Route::middleware('web')->group(function (): void {
        Particle::ops('exec', 'exec-dashboard', [
            ReadBoardLens::class, SwitchBoardLens::class,
            ReadScenarios::class, PreviewScenario::class, SaveScenario::class, DeleteScenario::class,
        ]);
    });
    app('router')->getRoutes()->refreshNameLookups();

    Gate::define('entitlement:exec.view', fn (?Localization25User $user): bool => in_array($user?->email, ['operator@beam.test', 'seat@beam.test'], true));
    Gate::define('entitlement:exec.scenario.manage', fn (?Localization25User $user): bool => in_array($user?->email, ['operator@beam.test', 'manage-only@beam.test'], true));

    $this->operator = Localization25User::create(['id' => (string) Str::uuid(), 'email' => 'operator@beam.test']);
    $this->seat = Localization25User::create(['id' => (string) Str::uuid(), 'email' => 'seat@beam.test']);
    $this->member = Localization25User::create(['id' => (string) Str::uuid(), 'email' => 'member@beam.test']);
});

class Localization25User extends AuthUser
{
    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}

/** A fresh request scope, as a real host request is. */
function nextLocalization25Request(): void
{
    app()->forgetScopedInstances();
}

/** A two-member draft over the Demo blueprints. */
function localization25Draft(array $overrides = []): array
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

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/** Retain full raw JSON and headers; comparison permits only reviewed additive fields and volatile headers. */
function localization25Snapshot(string $name, TestResponse $response): void
{
    $snapshot = [
        'status' => $response->status(),
        'headers' => $response->headers->all(),
        'bodyRaw' => $response->getContent(),
        'body' => $response->json(),
    ];
    $capture = getenv('EXEC25_CAPTURE');
    if ($capture !== false && $capture !== '') {
        file_put_contents($capture, json_encode(['name' => $name, ...$snapshot], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }
    $baseline = getenv('EXEC25_BASELINE');
    if ($baseline !== false && $baseline !== '') {
        $rows = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($baseline, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $expected = collect($rows)->firstWhere('name', $name);
        expect($expected)->not->toBeNull($name);
        unset($expected['name']);
        $comparison = $snapshot;
        // The capture retains raw bytes. JSON formatting may differ when additive code fields are encoded.
        unset($expected['bodyRaw'], $comparison['bodyRaw']);
        foreach (['date', 'set-cookie'] as $volatileHeader) {
            unset($expected['headers'][$volatileHeader], $comparison['headers'][$volatileHeader]);
        }
        ksort($expected['headers']);
        ksort($comparison['headers']);
        foreach (['code', 'codes'] as $addition) {
            if (! array_key_exists($addition, $expected['body']) && array_key_exists($addition, $comparison['body'])) {
                if ($addition === 'code') {
                    expect(in_array($name, ['save-board-seat'], true))->toBeTrue($name);
                    expect($comparison['body']['code'])->toBeString();
                } else {
                    expect(array_keys($comparison['body']['codes']))->toBe(array_keys($comparison['body']['errors']));
                    foreach ($comparison['body']['errors'] as $field => $messages) {
                        expect($comparison['body']['codes'][$field])->toHaveCount(count($messages));
                        foreach ($comparison['body']['codes'][$field] as $code) {
                            expect($code)->toBeString()->toMatch('/^[a-z][a-z0-9_]*$/D');
                        }
                    }
                }
                unset($comparison['body'][$addition]);
            }
        }
        expect($comparison)->toBe($expected, $name);
    }
}

it('preserves complete English bodies on real exec operation routes', function () {
    $requests = [
        'lens-invalid' => ['/exec/switch-lens', ['lens' => 'absent']],
        'scenario-file' => ['/exec/delete-scenario', ['id' => 'demo-mixed-book']],
        'scenario-missing' => ['/exec/delete-scenario', ['id' => 'never-saved']],
        'save-declared' => ['/exec/save-scenario', [...localization25Draft(), 'id' => 'demo-mixed-book']],
        'save-format' => ['/exec/save-scenario', [...localization25Draft(), 'id' => 'Board-Upside']],
        'save-blueprint' => ['/exec/save-scenario', [...localization25Draft(['members' => [['blueprint' => 'absent']]]), 'id' => 'board-upside']],
        'save-multiple' => ['/exec/save-scenario', ['id' => 'Bad ID', 'label' => '', 'members' => [['blueprint' => 'absent', 'count' => 0, 'startOffsetMonths' => -1]], 'churnRate' => 1.5]],
    ];
    foreach ($requests as $name => [$url, $body]) {
        nextLocalization25Request();
        $response = $this->actingAs($this->operator)->postJson($url, $body)->assertUnprocessable();
        localization25Snapshot($name, $response);
        if ($name === 'save-multiple') {
            expect(count($response->json('errors')))->toBeGreaterThanOrEqual(5);
        }
    }
    $manageOnly = Localization25User::create(['id' => (string) Str::uuid(), 'email' => 'manage-only@beam.test']);
    foreach (['save', 'delete'] as $op) {
        nextLocalization25Request();
        $response = $this->actingAs($manageOnly)->postJson('/exec/'.$op.'-scenario', [...localization25Draft(), 'id' => 'board-upside']);
        if ($op === 'save') {
            $response->assertForbidden();
            expect($response->json('message'))->toBe('Saving a scenario needs a board seat (exec.view).');
        } else {
            // Particle input validation rejects an unstored id before the delete handler's seat check.
            $response->assertUnprocessable();
            expect($response->json('errors.id'))->toBe(['There is no stored scenario with that id.']);
        }
        localization25Snapshot($op.'-board-seat', $response);
    }
    // A stored id passes delete input validation and reaches the actual authorization boundary.
    nextLocalization25Request();
    $this->actingAs($this->operator)->postJson('/exec/save-scenario', [...localization25Draft(), 'id' => 'board-upside'])->assertSuccessful();
    nextLocalization25Request();
    $deleteSeat = $this->actingAs($manageOnly)->postJson('/exec/delete-scenario', ['id' => 'board-upside'])->assertForbidden();
    expect($deleteSeat->json('message'))->toBe('Deleting a scenario needs a board seat (exec.view).');
    nextLocalization25Request();
    DB::table('plans')->where('slug', 'starter')->delete();
    $response = $this->actingAs($this->operator)->postJson('/exec/preview-scenario', localization25Draft(['members' => [['blueprint' => 'demo-tessaline-works', 'count' => 2]]]));
    expect($response->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    expect($response->json('errors.members.0'))->toBeString()->toContain('cannot be priced: this host has no plan with the slug');
    localization25Snapshot('preview-unpriced', $response);
    // Fault-inject unknown downstream failures at the builder seam, while retaining the real Particle/DTO/handle path.
    // Container resolves this port by its key; no production class or method signature is changed.
    $diagnostic = "Unknown downstream 'diagnostic' :parameter <node> — unchanged.";
    foreach (['preview', 'save'] as $operation) {
        nextLocalization25Request();
        app()->instance(ScenarioBuilder::class, new class($diagnostic)
        {
            public function __construct(private readonly string $diagnostic) {}

            public function preview(ScenarioDraftData $draft): never
            {
                throw new InvalidArgumentException($this->diagnostic);
            }

            public function save(ScenarioSaveData $input, mixed $actor): never
            {
                throw new InvalidArgumentException($this->diagnostic);
            }
        });
        $response = $this->actingAs($this->operator)->postJson('/exec/'.$operation.'-scenario', [...localization25Draft(), 'id' => 'board-upside']);
        expect($response->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
        expect($response->json('errors')[$operation === 'preview' ? 'members' : 'id'])->toBe([$diagnostic]);
        localization25Snapshot('forward-'.$operation, $response);
    }
});

it('marks locally owned messages through actual validation and scenario preview operations', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate pseudo-locale proof follows original English baseline.');
    }
    config(['beam.localization.pseudo' => true]);
    app()->setLocale('en-XA');
    foreach ([
        ['/exec/switch-lens', ['lens' => 'absent']],
        ['/exec/delete-scenario', ['id' => 'demo-mixed-book']],
        ['/exec/delete-scenario', ['id' => 'never-saved']],
        ['/exec/save-scenario', [...localization25Draft(), 'id' => 'demo-mixed-book']],
        ['/exec/save-scenario', [...localization25Draft(), 'id' => 'Board-Upside']],
        ['/exec/save-scenario', [...localization25Draft(['members' => [['blueprint' => 'absent']]]), 'id' => 'board-upside']],
    ] as [$url, $body]) {
        nextLocalization25Request();
        $response = $this->actingAs($this->operator)->postJson($url, $body)->assertUnprocessable();
        foreach ($response->json('errors') as $field => $messages) {
            foreach ($messages as $message) {
                expect(PseudoLocale::isMarked($message))->toBeTrue($url.' '.$field);
            }
        }
    }
    nextLocalization25Request();
    $response = $this->actingAs($this->operator)->postJson('/exec/preview-scenario', localization25Draft())->assertOk();
    expect($response->json('data.label'))->toBe('Board upside');
    foreach (['revenue', 'trend'] as $series) {
        foreach ($response->json('data.'.$series) as $index => $figure) {
            expect(PseudoLocale::isMarked($figure['label']))->toBeTrue($series.' '.$index);
        }
    }
    expect($response->json('data.revenue.0.value'))->toBe('Board upside');
});

it('marks every runtime card label from the registry while retaining authored source values', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate pseudo-locale proof follows original English baseline.');
    }
    config(['beam.localization.pseudo' => true]);
    app()->setLocale('en-XA');
    $source = new InMemoryBoardSource(
        asOf: CarbonImmutable::parse('2026-08-20 12:00:00'),
        synthesizedFrom: 'Authored portfolio',
    );
    $registry = app(BoardCardRegistry::class);
    foreach ($registry->definitions() as $definition) {
        foreach ($registry->figures($definition, $source) as $figure) {
            expect(PseudoLocale::isMarked($figure->label))->toBeTrue($definition->key().' '.$figure->id);
        }
    }
    foreach (['pass', 'warn', 'fail'] as $status) {
        $signal = PostureSignal::$status('external-check', 'Unknown report diagnostic')
            ->measured(CarbonImmutable::parse('2026-08-19 12:00:00'));
        $posture = new InMemoryBoardSource(posture: [$signal]);
        $figures = (new PostureCard)->measure($posture);
        expect(PseudoLocale::isMarked($figures[0]->value))->toBeTrue($status)
            ->and($signal->detail)->toBe('Unknown report diagnostic');
    }
    foreach (['beam_exec_tones', 'beam_exec_observations'] as $handle) {
        foreach (BoardFacetOptions::choices($handle) as $option) {
            expect(PseudoLocale::isMarked($option['label']))->toBeTrue($handle.' '.$option['value']);
        }
    }
    foreach ([0, 1, 6] as $horizon) {
        foreach ([null, 'Authored rates'] as $rates) {
            $projection = new BoardProjection('2026-08', CarbonImmutable::parse('2026-08-01')->addMonths($horizon)->format('Y-m'), 'truth', $rates);
            expect(PseudoLocale::isMarked($projection->describe()))->toBeTrue();
            if ($rates !== null) {
                expect($projection->describe())->toContain($rates);
            }
        }
    }
    expect(BoardWatermark::TEXT)->toBe('Synthesized — not real data')
        ->and(BoardWatermark::PROJECTED_TEXT)->toBe('Projected — not real data');
});

it('marks runtime lens choices and authorization refusals on their real read and write routes', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate pseudo-locale proof follows original English baseline.');
    }
    config(['beam.localization.pseudo' => true]);
    app()->setLocale('en-XA');
    app()->instance(BoardSource::class, new InMemoryBoardSource);
    $lens = $this->actingAs($this->operator)->getJson('/exec/lens')->assertOk();
    foreach ($lens->json('data.options') as $option) {
        foreach (['label', 'description'] as $field) {
            expect(PseudoLocale::isMarked($option[$field]))->toBeTrue($option['value'].' '.$field);
        }
    }
    foreach ($lens->json('data.scenarios') as $option) {
        expect(PseudoLocale::isMarked($option['description']))->toBeTrue();
    }
    $manageOnly = Localization25User::create(['id' => (string) Str::uuid(), 'email' => 'manage-only@beam.test']);
    nextLocalization25Request();
    $this->actingAs($this->operator)->postJson('/exec/save-scenario', [...localization25Draft(), 'id' => 'board-upside'])->assertSuccessful();
    foreach (['save', 'delete'] as $op) {
        nextLocalization25Request();
        $response = $this->actingAs($manageOnly)->postJson('/exec/'.$op.'-scenario', [...localization25Draft(), 'id' => 'board-upside'])->assertForbidden();
        expect(PseudoLocale::isMarked($response->json('message')))->toBeTrue()
            ->and($response->json('code'))->toBe('scenario_'.$op.'_board_seat');
    }
    nextLocalization25Request();
    DB::table('plans')->where('slug', 'starter')->delete();
    $response = $this->actingAs($this->operator)->postJson('/exec/preview-scenario', localization25Draft(['members' => [['blueprint' => 'demo-tessaline-works', 'count' => 2]]]))->assertUnprocessable();
    expect(PseudoLocale::isMarked($response->json('errors.members.0')))->toBeTrue()
        ->and($response->json('codes.members.0'))->toBe('scenario_unpriced');
});

it('keeps real Spatie multi-error aggregation and adds one structural code per message', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate validator code metadata follows original English baseline.');
    }
    $payload = ['id' => 'Bad ID', 'label' => '', 'members' => [['blueprint' => 'absent', 'count' => 0, 'startOffsetMonths' => -1]], 'churnRate' => 1.5];
    $response = $this->actingAs($this->operator)->postJson('/exec/save-scenario', $payload)->assertUnprocessable();
    foreach ($response->json('errors') as $field => $messages) {
        expect($response->json('codes')[$field])->toHaveCount(count($messages));
    }
    // Dot-containing validation addresses are literal JSON object keys, not nested paths.
    $codes = $response->json('codes');
    expect($codes['id'])->toContain('scenario_id_format')
        ->and($codes['label'])->toContain('scenario_label_required')
        ->and($codes['members.0.blueprint'])->toContain('scenario_blueprint_unknown')
        ->and($codes['members.0.count'])->toContain('scenario_member_count_min')
        ->and($codes['members.0.startOffsetMonths'])->toContain('scenario_member_start_offset_min')
        ->and($codes['churnRate'])->toContain('scenario_churn_rate_max');

    try {
        ScenarioSaveData::validateAndCreate($payload);
        $this->fail('Invalid draft must be refused.');
    } catch (ValidationException $exception) {
        expect($exception)->toBeInstanceOf(ExecValidationException::class)
            ->toBeInstanceOf(CodedValidationException::class);
        $original = new ValidationException($exception->validator, $exception->response, $exception->errorBag);
        expect($exception->errors())->toBe($original->errors())
            ->and($exception->getMessage())->toBe($original->getMessage())
            ->and($exception->status)->toBe($original->status)
            ->and($exception->codes())->toBe($codes);
    }
});

it('keeps original validator response and error bag and uses generic codes for unknown structural rules', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate validator code metadata follows original English baseline.');
    }
    $validator = app('validator')->make(['futureField' => 'bad'], ['futureField' => 'email']);
    $validator->fails();
    $originalResponse = response('Original explicit response', 422);
    $exception = new ExecValidationException($validator, $originalResponse, 'original-bag');
    $exception->redirectTo('/original-redirect');
    expect($exception->validator)->toBe($validator)
        ->and($exception->response)->toBe($originalResponse)
        ->and($exception->errorBag)->toBe('original-bag')
        ->and($exception->redirectTo)->toBe('/original-redirect')
        ->and($exception->errors())->toBe($validator->errors()->messages())
        ->and($exception->codes()['futureField'])->toBe(['exec_input_invalid']);
    $validator = app('validator')->make([], []);
    $validator->after(fn ($validator) => $validator->errors()->add('externalField', 'Unknown diagnostic detail'));
    $validator->fails();
    $exception = new ExecValidationException($validator);
    expect($exception->errors()['externalField'])->toBe(['Unknown diagnostic detail'])
        ->and($exception->codes()['externalField'])->toBe(['exec_input_invalid']);
});

it('keeps forwarded diagnostic bytes as parameters beside stable preview and save codes', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate coded forwarded diagnostics follow original English baseline.');
    }
    $detail = "External diagnostic: 'literal' :parameter <node> — unchanged.";
    foreach ([
        ['preview', 'members', ExecError::ScenarioPreviewInvalid],
        ['save', 'id', ExecError::ScenarioSaveInvalid],
    ] as [$op, $field, $error]) {
        expect($error->message(['detail' => $detail]))->toBe($detail);
        Route::post('/exec25/forwarded-'.$op, fn () => throw CodedValidationException::for($field, $error, ['detail' => $detail]));
        $response = $this->postJson('/exec25/forwarded-'.$op)->assertUnprocessable();
        expect($response->json('errors')[$field])->toBe([$detail])
            ->and($response->json('codes')[$field])->toBe([$error->code()]);
    }
});

class Localization25RedirectDraft extends ScenarioDraftData
{
    public static function errorBag(): string
    {
        return 'exec-original-bag';
    }

    public static function redirect(): string
    {
        return '/exec-original-redirect';
    }
}

it('retains Spatie redirect and error bag through the real validateAndCreate pipeline', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate exception subtype proof follows original English baseline.');
    }
    try {
        Localization25RedirectDraft::validateAndCreate(['label' => '', 'members' => []]);
        $this->fail('Invalid draft must be refused.');
    } catch (ValidationException $exception) {
        expect($exception)->toBeInstanceOf(ExecValidationException::class)
            ->and($exception->errorBag)->toBe('exec-original-bag')
            ->and($exception->redirectTo)->toBe('/exec-original-redirect');
        foreach ($exception->errors() as $field => $messages) {
            expect($exception->codes()[$field])->toHaveCount(count($messages));
        }
    }
});

it('retains authorization parent type status and headers at the JSON boundary', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate exception subtype proof follows original English baseline.');
    }
    $exception = new ExecAccessDeniedException(ExecError::SaveBoardSeat);
    $exception->setHeaders(['X-Exec-Proof' => 'retained']);
    expect($exception)->toBeInstanceOf(AccessDeniedHttpException::class);
    Route::get('/exec25/header-proof', fn () => throw $exception);
    $this->getJson('/exec25/header-proof')->assertForbidden()->assertHeader('X-Exec-Proof', 'retained')
        ->assertJsonPath('message', 'Saving a scenario needs a board seat (exec.view).')
        ->assertJsonPath('code', 'scenario_save_board_seat');
});

class Localization25FutureRuleDraft extends ScenarioDraftData
{
    public static function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->addRules(['label' => ['email']]);
        $validator->after(fn ($validator) => $validator->errors()->add('futureField', 'Unknown future diagnostic'));
    }
}

it('uses structural fallback through a real Spatie DTO route for unknown rules and after-hook diagnostics', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate structural fallback proof follows original English baseline.');
    }
    Route::post('/exec25/future-rule', fn (Request $request) => Localization25FutureRuleDraft::validateAndCreate($request->all()));
    $response = $this->postJson('/exec25/future-rule', localization25Draft())->assertUnprocessable();
    expect($response->json('codes.label'))->toBe(['exec_input_invalid'])
        ->and($response->json('codes.futureField'))->toBe(['exec_input_invalid'])
        ->and($response->json('errors.futureField'))->toBe(['Unknown future diagnostic']);
});

it('renders projection and card dates in the current reader locale and keeps complete English plural sentences', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate reader-locale dates follow original English baseline.');
    }
    config(['beam.localization.supported' => ['en', 'fr']]);
    foreach ([0 => '0 months', 1 => '1 month', 6 => '6 months'] as $horizon => $phrase) {
        $projection = new BoardProjection('2026-08', CarbonImmutable::parse('2026-08-01')->addMonths($horizon)->format('Y-m'), 'truth', 'Authored rates');
        expect($projection->describe())->toBe("Truth, August 2026 held flat, {$phrase} ahead at Authored rates's rates");
    }
    $source = new InMemoryBoardSource(asOf: CarbonImmutable::parse('2026-08-20 12:00:00'));
    $card = new MrrTrendCard;
    expect(array_slice($card->measure($source), -1)[0]->label)->toBe('Aug 2026');
    app()->setLocale('fr');
    expect(array_slice($card->measure($source), -1)[0]->label)->toBe('août 2026');
    $projection = new BoardProjection('2026-08', '2026-09', 'truth');
    expect($projection->describe())->toContain('août 2026')->not->toContain('August');
    $signal = PostureSignal::pass('report', 'Raw report detail')->measured(CarbonImmutable::parse('2026-08-19 12:00:00'));
    $posture = new InMemoryBoardSource(posture: [$signal], asOf: CarbonImmutable::parse('2026-08-20 12:00:00'));
    $figures = (new PostureCard)->measure($posture);
    expect(array_slice($figures, -1)[0]->value)->toContain('jour');
});
