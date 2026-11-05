<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Exec\Realm\ExecRealm;
use Splicewire\Beam\Ia\RealmProfiles;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmGateAbility;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Surgeon\RealmGateCoverageAudit;

it('lists the realm marker, the profile and the entitlement key in config', function () {
    expect(config('beam.core.realms.classes'))->toContain(ExecRealm::class)
        ->and(config('beam.core.entitlements.keys'))->toContain('exec.view')
        ->and((new RealmProfiles)->for('exec'))->toBe([
            'label' => 'Board',
            'home' => 'exec.home',
            'surface' => 'app',
            'gate' => ['entitlement' => 'exec.view', 'mode' => 'hard'],
        ]);
});

it('registers exec as a central, unguarded realm at /exec beside the four base realms', function () {
    $realms = app(RealmRegistry::class);
    $exec = $realms->resolve('exec');

    expect(array_keys($realms->all()))->toContain('operator', 'tenant', 'user', 'site', 'exec')
        ->and($exec->routeBase)->toBe('/exec')
        ->and($exec->central)->toBeTrue()
        ->and($exec->guard)->toBeNull()
        ->and($exec->stack)->toBe([]);
});

it('gates the realm on entitlement:exec.view, not the central-realm default os.operate', function () {
    expect(RealmGateAbility::for('exec', app(RealmRegistry::class)))->toBe('entitlement:exec.view')
        ->and(app(Gate::class)->has('entitlement:exec.view'))->toBeTrue();
});

it('gets its dashboard from beam-ux at boot, carrying the realm gate as its policy', function () {
    // Whether link 58 or its `booted()` sweep registered it is not visible here: both write as beam-ux. The
    // moment that matters, a host route file reading `RouteContextProjector::hrefs('exec')`, is proved at a
    // host (exec-board ticket 03's starter proof).
    $registry = app(ParticleResourceRegistry::class);

    expect($registry->has('exec-dashboard'))->toBeTrue();

    $dashboard = $registry->get('exec-dashboard');

    expect($dashboard->policy)->toBe('entitlement:exec.view')
        ->and($dashboard->routeName)->toBe(RealmDashboard::routeNameFor('exec'))
        ->and($dashboard->readOnly)->toBeTrue();
});

it('seats one board section in the exec realm, landing on the dashboard and gated on exec.view', function () {
    $seats = app(NavSectionRegistry::class)->for('exec');

    expect(array_map(fn (NavSection $s): array => [$s->key, $s->href, $s->entitlement], $seats))
        ->toBe([['board', '/exec/dashboard', ['exec.view']]]);
});

it('leaves no orphaned realm gate: the exec gate names a registered realm', function () {
    $findings = app(RealmGateCoverageAudit::class)->run();

    $warned = array_filter($findings, fn (Finding $f): bool => $f->check === RealmGateCoverageAudit::CHECK_ORPHANED);

    expect($warned)->toBe([]);
});

it('lets a host override the profile key by key without losing the gate', function () {
    config(['beam.core.realms.exec' => ['label' => 'Boardroom']]);

    (new BeamExecServiceProvider($this->app))->register();

    expect((new RealmProfiles)->for('exec')['label'])->toBe('Boardroom')
        ->and((new RealmProfiles)->for('exec')['gate']['entitlement'])->toBe('exec.view')
        ->and(array_count_values(config('beam.core.realms.classes'))[ExecRealm::class])->toBe(1);
});

it('writes the realm in the register phase, before any provider boots', function () {
    // beam reads `beam.core.realms.classes` in its boot and beam-ux registers the dashboard at boot link 58, so the
    // marker must be in config once register() returns. This harness lists exec's provider first, which would let a
    // boot-phase write pass every other test here; this one runs register() alone.
    config(['beam.core.realms.classes' => [], 'beam.core.entitlements.keys' => [], 'beam.core.realms.exec' => []]);

    (new BeamExecServiceProvider($this->app))->register();

    expect(config('beam.core.realms.classes'))->toBe([ExecRealm::class])
        ->and(config('beam.core.entitlements.keys'))->toBe(['exec.view'])
        ->and(config('beam.core.realms.exec.gate.entitlement'))->toBe('exec.view');
});
