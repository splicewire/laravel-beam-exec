<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\Cards\TenantsCard;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
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
});

it('reads every tenant from the central table, synthetic ones included', function () {
    $tenants = (new LiveBoardSource)->tenants();
    $byId = collect($tenants)->keyBy('id');

    expect($tenants)->each->toBeInstanceOf(BoardTenant::class)
        ->and($byId->keys()->sort()->values()->all())->toBe(['acme', 'demo-orrowby', 'globex', 'initech'])
        ->and($byId['acme']->status)->toBe('active')
        ->and($byId['acme']->name)->toBe('Acme')
        ->and($byId['globex']->suspended)->toBeTrue()
        ->and($byId['initech']->status)->toBe('provisioning')
        ->and($byId['demo-orrowby']->synthetic)->toBeTrue()
        ->and($byId['acme']->synthetic)->toBeFalse()
        ->and($byId['acme']->createdAt?->toDateString())->toBe('2024-03-01');
});

it('reads the roster once per source, however many cards ask', function () {
    $source = new LiveBoardSource;
    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $source->tenants();
    $source->tenants();

    expect($queries)->toBe(1);
});

it('feeds the tenants card the live headcount', function () {
    $figures = collect((new TenantsCard)->measure(new LiveBoardSource))->pluck('value', 'id')->all();

    expect($figures)->toBe(['total' => 4, 'active' => 2, 'new' => 1, 'synthetic' => 1]);
});
