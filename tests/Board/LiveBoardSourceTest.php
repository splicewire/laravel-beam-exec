<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Board\Cards\PlanMixCard;
use Splicewire\Beam\Exec\Board\Cards\RiskCard;
use Splicewire\Beam\Exec\Board\Cards\TenantsCard;
use Splicewire\Beam\Exec\Board\Cards\UsageCard;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;
use Splicewire\Beam\Tenancy\Tenant;

/*
 * The live source against a real central `tenants` table: stancl's shape, `id` + `name` columns and everything else
 * folded into the `data` JSON (provisioning status, suspension, the synthetic flag).
 */
beforeEach(function () {
    config([
        'tenancy.tenant_model' => Tenant::class,
        'tenancy.database.central_connection' => config('database.default'),
    ]);

    Schema::create('tenants', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->string('parent_tenant_id')->nullable();
        $table->json('data')->nullable();
        $table->timestamps();
    });

    Schema::create('plans', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('slug')->unique();
        $table->json('settings')->nullable();
        $table->timestamps();
    });

    Schema::create('subscriptions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('party_id');
        $table->string('party_type');
        $table->uuid('plan_id')->nullable();
        $table->string('subscribable_type')->nullable();
        $table->string('subscribable_id')->nullable();
        $table->integer('commitment_months')->default(1);
        $table->json('overrides')->nullable();
        $table->timestamp('started_at');
        $table->timestamp('ended_at')->nullable();
        $table->timestamp('paused_at')->nullable();
        $table->timestamps();
    });

    Schema::create('central_usage_summaries', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('month');
        $table->decimal('total_cost_usd', 12, 6)->default(0);
        $table->unsignedBigInteger('total_tokens')->default(0);
        $table->json('models')->nullable();
        $table->json('meters')->nullable();
        $table->timestamps();
    });

    Schema::create('tenant_usage_summaries', function (Blueprint $table) {
        $table->string('tenant_id');
        $table->uuid('usage_summary_id');
        $table->timestamps();
    });

    Tenant::withoutEvents(function () {
        $make = function (string $id, array $data, string $created): void {
            $tenant = new Tenant(['id' => $id, 'name' => ucfirst($id)]);
            foreach ($data as $key => $value) {
                $tenant->{$key} = $value;
            }
            $tenant->created_at = $created;
            $tenant->save();
        };

        $make('acme', ['provisioning_status' => 'active'], '2024-03-01 10:00:00');
        $make('globex', ['provisioning_status' => 'active', 'suspended_at' => '2025-01-01'], '2024-05-01 10:00:00');
        $make('initech', ['provisioning_status' => 'provisioning'], now()->toDateTimeString());
        $make('demo-orrowby', ['provisioning_status' => 'active', 'synthetic' => true], '2025-11-01 00:00:00');
    });

    DB::table('tenants')->where('id', 'initech')->update(['parent_tenant_id' => 'acme']);

    $party = (new Tenant)->getMorphClass();
    foreach (['pro' => 'Pro', 'starter' => 'Starter'] as $slug => $name) {
        DB::table('plans')->insert(['id' => "00000000-0000-0000-0000-00000000{$slug}", 'name' => $name, 'slug' => $slug]);
    }
    foreach ([
        // [id, party, plan|null, commitment, started, ended, paused]
        ['s1', 'acme', 'pro', 12, '2025-01-01', null, null],
        ['s2', 'globex', 'starter', 1, '2025-02-01', '2025-12-31', '2025-06-01'],
        ['s3', 'demo-orrowby', 'pro', 1, '2025-11-01', null, null],
        ['s4', 'acme', null, 1, '2025-01-01', null, null],            // a marketplace listing, not a plan
    ] as [$id, $tenant, $plan, $commitment, $started, $ended, $paused]) {
        DB::table('subscriptions')->insert([
            'id' => $id, 'party_id' => $tenant, 'party_type' => $party,
            'plan_id' => $plan === null ? null : "00000000-0000-0000-0000-00000000{$plan}",
            'subscribable_type' => $plan === null ? 'listing' : null, 'subscribable_id' => $plan === null ? 'l1' : null,
            'commitment_months' => $commitment, 'started_at' => $started, 'ended_at' => $ended, 'paused_at' => $paused,
        ]);
    }
    foreach ([
        // [id, tenants, month, legacy cost, legacy tokens, meters|null]
        ['u1', ['acme'], '2026-08', 0, 0, ['openai.tokens' => ['cost_usd' => 1.5, 'quantity' => 100], 'storage.gb' => ['cost_usd' => 0.5, 'quantity' => 2]]],
        ['u2', ['acme'], '2026-08', 0, 0, ['openai.tokens' => ['cost_usd' => 0.25, 'quantity' => 10]]],
        ['u3', ['globex'], '2026-08', 0.75, 750, null],                  // legacy: token spend only
        ['u4', ['demo-orrowby'], '2026-08', 0, 0, ['openai.tokens' => ['cost_usd' => 9.0, 'quantity' => 900]]],
        ['u5', ['initech'], '2026-07', 0, 0, ['storage.gb' => ['cost_usd' => 0.1, 'quantity' => 1]]],
    ] as [$id, $tenants, $month, $cost, $tokens, $meters]) {
        DB::table('central_usage_summaries')->insert([
            'id' => $id, 'month' => $month, 'total_cost_usd' => $cost, 'total_tokens' => $tokens,
            'meters' => $meters === null ? null : json_encode($meters),
        ]);
        foreach ($tenants as $tenant) {
            DB::table('tenant_usage_summaries')->insert(['tenant_id' => $tenant, 'usage_summary_id' => $id]);
        }
    }
});

/** @return array<string, array{float, float}> "tenant meter" => [cost, quantity] */
function usageRows(array $rows): array
{
    return collect($rows)->mapWithKeys(fn (BoardUsage $u) => ["{$u->tenantId} {$u->meter}" => [$u->costUsd, $u->quantity]])->sortKeys()->all();
}

it('reads every tenant from the central table, synthetic ones included', function () {
    $tenants = app(LiveBoardSource::class)->tenants();
    $byId = collect($tenants)->keyBy('id');

    expect($tenants)->each->toBeInstanceOf(BoardTenant::class)
        ->and($byId->keys()->sort()->values()->all())->toBe(['acme', 'demo-orrowby', 'globex', 'initech'])
        ->and($byId['acme']->status)->toBe('active')
        ->and($byId['acme']->name)->toBe('Acme')
        ->and($byId['globex']->suspended)->toBeTrue()
        ->and($byId['initech']->status)->toBe('provisioning')
        ->and($byId['demo-orrowby']->synthetic)->toBeTrue()
        ->and($byId['acme']->synthetic)->toBeFalse()
        ->and($byId['acme']->createdAt?->toDateString())->toBe('2024-03-01')
        ->and($byId['initech']->brokerId)->toBe('acme')
        ->and($byId['acme']->brokerId)->toBeNull();
});

it('reads the roster once per source, however many cards ask', function () {
    $source = app(LiveBoardSource::class);
    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $source->tenants();
    $source->tenants();

    expect($queries)->toBe(1);
});

it('feeds the tenants card the live headcount', function () {
    $figures = collect((new TenantsCard)->measure(app(LiveBoardSource::class)))->pluck('value', 'id')->all();

    expect($figures)->toBe(['total' => 4, 'active' => 2, 'new' => 1, 'synthetic' => 1]);
});

it('feeds the risk card the live suspensions, with an empty doctor manifest adding no failing check', function () {
    app()->instance(BeamDoctorManifest::class, new BeamDoctorManifest);

    $figures = collect((new RiskCard)->measure(app(LiveBoardSource::class)))->pluck('value', 'id')->all();

    expect($figures)->toBe(['open' => 1, 'suspended' => 1, 'failed-provisioning' => 0, 'failing-checks' => 0]);
});

it('reads the truth without synthetic tenants, through the tenant model\'s notSynthetic scope', function () {
    $truth = LiveBoardSource::truth();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $ids = collect($truth->tenants())->pluck('id')->sort()->values()->all();

    expect($truth->lens())->toBe('truth')
        ->and($ids)->toBe(['acme', 'globex', 'initech'])
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('synthetic')
        ->and(collect((new TenantsCard)->measure($truth))->pluck('value', 'id')->all())
        ->toBe(['total' => 3, 'active' => 1, 'new' => 1, 'synthetic' => 0]);
});

it('reads plan subscriptions only, ended ones included, and none of a tenant the source does not see', function () {
    $live = collect(LiveBoardSource::live()->subscriptions())->keyBy('tenantId');
    $truth = collect(LiveBoardSource::truth()->subscriptions())->pluck('tenantId')->sort()->values()->all();

    expect($live->keys()->sort()->values()->all())->toBe(['acme', 'demo-orrowby', 'globex'])
        ->and($live['acme'])->toBeInstanceOf(BoardSubscription::class)
        ->and($live['acme']->planKey)->toBe('pro')
        ->and($live['acme']->planName)->toBe('Pro')
        ->and($live['acme']->isAnnual())->toBeTrue()
        ->and($live['acme']->endedAt)->toBeNull()
        ->and($live['globex']->endedAt?->toDateString())->toBe('2025-12-31')
        ->and($live['globex']->paused)->toBeTrue()
        ->and($truth)->toBe(['acme', 'globex']);
});

it('reads one month of usage per tenant and meter, the legacy token rollup included, through the tenant set', function () {
    expect(usageRows(LiveBoardSource::live()->usage('2026-08')))->toBe([
        'acme openai.tokens' => [1.75, 110.0],
        'acme storage.gb' => [0.5, 2.0],
        'demo-orrowby openai.tokens' => [9.0, 900.0],
        'globex openai.tokens' => [0.75, 750.0],
    ])
        ->and(usageRows(LiveBoardSource::truth()->usage('2026-08')))->not->toHaveKey('demo-orrowby openai.tokens')
        ->and(usageRows(LiveBoardSource::live()->usage('2026-07')))->toBe(['initech storage.gb' => [0.1, 1.0]])
        ->and(LiveBoardSource::live()->usage('2020-01'))->toBe([]);
});

it('binds the truth while the reporting toggle excludes synthetic tenants, and the live reading otherwise', function () {
    expect(app(BoardSource::class)->lens())->toBe('live');

    // The operator's runtime override (the cache key SyntheticReporting::set() writes): no redeploy, next request.
    Cache::forever(SyntheticReporting::CACHE_KEY, true);
    app()->forgetScopedInstances();

    expect(app(BoardSource::class)->lens())->toBe('truth')
        ->and(collect(app(BoardSource::class)->tenants())->pluck('id'))->not->toContain('demo-orrowby');

    Cache::forget(SyntheticReporting::CACHE_KEY);
    app()->forgetScopedInstances();

    expect(app(BoardSource::class)->lens())->toBe('live');

    // And the deploy-time answer, from config.
    config(['beam.tenancy.synthetic.exclude_from_reporting' => true]);
    app()->forgetScopedInstances();

    expect(app(BoardSource::class)->lens())->toBe('truth');
});

it('feeds the plan-mix and usage cards from the live tables', function () {
    CarbonImmutable::setTestNow('2026-08-20 12:00:00');
    $source = LiveBoardSource::live();
    $mix = collect((new PlanMixCard)->measure($source))->pluck('value', 'id')->all();
    $usage = collect((new UsageCard)->measure($source))->pluck('value', 'id')->all();

    // globex lapsed at 2025-12-31; acme (annual) and the synthetic demo tenant hold Pro now.
    expect($mix)->toBe(['subscribed' => 2, 'plan-pro' => 2, 'annual-share' => '50.0%', 'paused' => 0, 'unsubscribed' => 1])
        ->and($usage['cost-this-month'])->toBe('$12.00')
        ->and($usage['top-meter'])->toBe('openai.tokens')
        ->and($source->asOf()->toDateTimeString())->toBe('2026-08-20 12:00:00');

    CarbonImmutable::setTestNow();
});
