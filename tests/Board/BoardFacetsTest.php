<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Exec\Board\BoardCardBacking;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Posture\PostureSignal;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;
use Splicewire\Beam\Filters\ResourceFilters;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('serves each cards vocabulary through the shared filter schema', function () {
    $this->actingAs(new GenericUser(['id' => 'viewer']));
    request()->setUserResolver(fn () => auth()->user());
    Gate::before(fn () => true);
    foreach (['revenue' => ['month'], 'usage' => ['month'], 'growth' => ['month'], 'mrr-trend' => ['observation'], 'posture' => ['tone'], 'risk' => ['tone'], 'tenants' => ['status', 'plan'], 'plan-mix' => []] as $card => $names) {
        $schema = app(ResourceFilters::class)->schema('exec-'.$card)->data;
        expect(array_keys((array) $schema['properties']))->toBe($names);
        foreach ((array) $schema['properties'] as $property) {
            expect($property)->toHaveKey('x-filter');
        }
    }
});

it('remeasures usage and growth for the selected month without changing the lens', function () {
    app()->instance(BoardSource::class, new InMemoryBoardSource(
        tenants: [new BoardTenant('old', 'Old', 'active', createdAt: CarbonImmutable::parse('2020-01-01')), new BoardTenant('july', 'July', 'active', createdAt: CarbonImmutable::parse('2026-07-05')), new BoardTenant('august', 'August', 'active', createdAt: CarbonImmutable::parse('2026-08-05'))],
        usage: [new BoardUsage('old', '2026-07', 'tokens', 10, 1), new BoardUsage('old', '2026-08', 'tokens', 25, 1)],
        synthesizedFrom: 'Fixture scenario',
    ));
    $usage = (new BoardCardBacking('usage'))->records(['month' => '2026-07'], null, 20)->items();
    $growth = (new BoardCardBacking('growth'))->records(['month' => '2026-07'], null, 20)->items();
    expect($usage[0]->id)->toBe('synthesized')->and($growth[0]->id)->toBe('synthesized');
    $values = array_column($growth, 'value', 'id');
    expect($values['new-this-month'])->toBe(1)->and($values['new-last-month'])->toBe(0);
    expect(array_column($usage, 'value', 'id')['cost-this-month'])->toContain('10');
    expect(app(BoardSource::class)->asOf()->format('Y-m'))->toBe('2026-08');
});

it('keeps the synthetic watermark when a facet removes every data row', function () {
    app()->instance(BoardSource::class, new InMemoryBoardSource(synthesizedFrom: 'Fixture scenario'));
    $rows = (new BoardCardBacking('mrr-trend'))->records(['observation' => 'forecast'], null, 20)->items();
    expect(array_column($rows, 'id'))->toBe(['synthesized']);
});

it('filters tenant headcounts by current plan and status using the existing source', function () {
    $at = CarbonImmutable::parse('2026-08-20');
    app()->instance(BoardSource::class, new InMemoryBoardSource(
        tenants: [new BoardTenant('a', 'A', 'active'), new BoardTenant('b', 'B', 'failed'), new BoardTenant('c', 'C', 'active', brokerId: 'a')],
        subscriptions: [new BoardSubscription('a', 'pro', 'Pro', $at->subYear()), new BoardSubscription('b', 'pro', 'Pro', $at->subYear(), endedAt: $at->subDay()), new BoardSubscription('b', 'basic', 'Basic', $at->subDay())],
        asOf: $at,
    ));
    $rows = (new BoardCardBacking('tenants'))->records(['plan' => 'pro', 'status' => 'active'], null, 20)->items();
    expect(array_column($rows, 'value', 'id')['total'])->toBe(2);
    $none = (new BoardCardBacking('tenants'))->records(['plan' => 'pro', 'status' => 'failed'], null, 20)->items();
    expect(array_column($none, 'value', 'id')['total'])->toBe(0);
});

it('remeasures revenue from the selected month and keeps invalid months at the source clock', function () {
    app()->instance(BoardSource::class, new InMemoryBoardSource(revenue: [
        new PartyPeriodRevenue('a', '2026-07', 'pro', 10),
        new PartyPeriodRevenue('a', '2026-08', 'pro', 25),
    ]));
    $backing = new BoardCardBacking('revenue');
    $july = $backing->records(['month' => '2026-07'], null, 20)->items();
    expect(array_column($july, 'value', 'id')['mrr'])->toContain('10');
    $current = array_column($backing->records([], null, 20)->items(), 'value', 'id');
    foreach (['2026-13', '2027-01', ['2026-07']] as $invalid) {
        expect(array_column($backing->records(['month' => $invalid], null, 20)->items(), 'value', 'id'))->toBe($current);
    }
});

it('applies tone before stamping and retains the watermark on an empty tone view', function () {
    app()->instance(BoardSource::class, new InMemoryBoardSource(
        posture: [PostureSignal::fail('db', 'Broken')],
        synthesizedFrom: 'Fixture scenario',
    ));
    foreach (['posture', 'risk'] as $card) {
        $rows = (new BoardCardBacking($card))->records(['tone' => 'danger'], null, 20)->items();
        expect($rows[0]->id)->toBe('synthesized');
        foreach (array_slice($rows, 1) as $row) {
            expect($row->tone)->toBe('danger');
        }
        $empty = (new BoardCardBacking($card))->records(['tone' => 'busy'], null, 20)->items();
        expect(array_column($empty, 'id'))->toBe(['synthesized']);
    }
});

it('serves declared picker options through the shared endpoint and keeps unrelated handles unreachable', function () {
    $this->actingAs(new GenericUser(['id' => 'viewer']));
    request()->setUserResolver(fn () => auth()->user());
    Gate::before(fn () => true);
    $options = app(ResourceFilters::class)->options('exec-mrr-trend', 'beam_exec_observations')->data;
    expect(array_map(fn ($row) => $row->value, $options))->toBe(['measured', 'forecast']);
    expect(fn () => app(ResourceFilters::class)->options('exec-plan-mix', 'beam_exec_observations'))->toThrow(HttpException::class);
});

it('keeps the leading watermark on a filtered Frame page and reads no database', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('f', 32))]);
    $this->actingAs(new GenericUser(['id' => 'viewer']));
    Gate::before(fn () => true);
    app()->instance(BoardSource::class, new InMemoryBoardSource(synthesizedFrom: 'Fixture scenario'));
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->getJson('/frame/resources/exec-mrr-trend?filter[observation]=forecast')
        ->assertOk()->assertJsonPath('data.0.id', 'synthesized')->assertJsonCount(1, 'data');
    expect($queries)->toBe([]);
});
