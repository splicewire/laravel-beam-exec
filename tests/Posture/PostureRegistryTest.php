<?php

require_once __DIR__.'/../Support/Posture.php';

use Illuminate\Support\Facades\Cache;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
use Splicewire\Beam\Exec\Posture\Contributors\DoctorPostureContributor;
use Splicewire\Beam\Exec\Posture\PostureRegistry;
use Splicewire\Beam\Exec\Posture\PostureSignal;
use Splicewire\Beam\Exec\Tests\Support\FailingGateAudit;
use Splicewire\Beam\Exec\Tests\Support\LedgerPostureContributor;
use Splicewire\Beam\Exec\Tests\Support\MixedReadinessAudit;
use Splicewire\Beam\Exec\Tests\Support\NotAContributor;
use Splicewire\Beam\Exec\Tests\Support\ThrowingPostureContributor;

/** @return list<array{string|null, string, string, bool}> */
function postureRows(array $signals): array
{
    return array_map(
        fn (PostureSignal $s): array => [$s->source, $s->check, $s->status->value, $s->conclusive],
        $signals,
    );
}

/** The live source's posture, with the doctor manifest holding only the audits a test registers. */
function livePosture(): array
{
    return app(LiveBoardSource::class)->posture();
}

beforeEach(function () {
    MixedReadinessAudit::$runs = 0;
    app()->instance(BeamDoctorManifest::class, new BeamDoctorManifest);
});

it('reads the doctor-backed default from config', function () {
    $registry = app(PostureRegistry::class);

    expect($registry->contributorKeys())->toBe(['doctor'])
        ->and($registry->resolve('doctor'))->toBe(DoctorPostureContributor::class)
        ->and($registry->registrantOf('doctor'))->toBe('config:beam.exec.posture');
});

it('takes a package contributor registered from its own provider, and keeps the doctor', function () {
    app(PostureRegistry::class)->register('ledger', LedgerPostureContributor::class, 'acme/ledger');

    expect(app(PostureRegistry::class)->contributorKeys())->toBe(['doctor', 'ledger'])
        ->and(app(PostureRegistry::class)->registrantOf('ledger'))->toBe('acme/ledger');
});

it('reaches a contributor registered through afterResolving before the registry was built', function () {
    $this->app->forgetInstance(PostureRegistry::class);
    $this->app->afterResolving(
        PostureRegistry::class,
        fn (PostureRegistry $r) => $r->register('ledger', LedgerPostureContributor::class),
    );

    expect(app(PostureRegistry::class)->contributorKeys())->toBe(['doctor', 'ledger'])
        ->and(app(PostureRegistry::class)->contributorKeys())->toBe(['doctor', 'ledger']);
});

it('refuses a duplicate key, a bad key and a class that is not a contributor', function (string $key, mixed $entry, string $message) {
    expect(fn () => app(PostureRegistry::class)->register($key, $entry))->toThrow($message);
})->with([
    'duplicate' => ['doctor', LedgerPostureContributor::class, 'rejects duplicates'],
    'bad key' => ['Ledger_Posture', LedgerPostureContributor::class, 'kebab-case'],
    'not a contributor' => ['ledger', NotAContributor::class, 'must be the class-string'],
    'not a string' => ['ledger', 42, 'must be the class-string'],
]);

it('turns every doctor finding into a signal, status and conclusiveness unchanged', function () {
    app(BeamDoctorManifest::class)->register('acme/host', MixedReadinessAudit::class);

    expect(postureRows(livePosture()))->toBe([
        ['doctor', 'mixed.clean', 'pass', true],
        ['doctor', 'mixed.drift', 'warn', true],
        ['doctor', 'mixed.empty', 'pass', false],
    ]);
});

it('reads a failing gate audit\'s report instead of throwing', function () {
    app(BeamDoctorManifest::class)
        ->register('acme/host', MixedReadinessAudit::class)
        ->register('acme/host', FailingGateAudit::class, gate: true);

    $signals = livePosture();

    expect(array_column(postureRows($signals), 1))->toBe(['mixed.clean', 'mixed.drift', 'mixed.empty', 'gate.broken'])
        ->and(end($signals)->status)->toBe(DoctorStatus::Fail);
});

it('caches the doctor run for the configured ttl, and runs it afresh at zero', function () {
    app(BeamDoctorManifest::class)->register('acme/host', MixedReadinessAudit::class);

    app(DoctorPostureContributor::class)->signals();
    app(DoctorPostureContributor::class)->signals();

    expect(MixedReadinessAudit::$runs)->toBe(1)
        ->and(Cache::has(DoctorPostureContributor::CACHE_KEY))->toBeTrue();

    config(['beam.exec.doctor_posture_ttl' => 0]);
    Cache::forget(DoctorPostureContributor::CACHE_KEY);

    app(DoctorPostureContributor::class)->signals();
    app(DoctorPostureContributor::class)->signals();

    expect(MixedReadinessAudit::$runs)->toBe(3)
        ->and(Cache::has(DoctorPostureContributor::CACHE_KEY))->toBeFalse();
});

it('asks each contributor in order, stamps its key, and reports a throwing one as one failing signal', function () {
    app(BeamDoctorManifest::class)->register('acme/host', FailingGateAudit::class);
    app(PostureRegistry::class)
        ->register('broken', ThrowingPostureContributor::class)
        ->register('ledger', LedgerPostureContributor::class);

    $signals = livePosture();

    expect(postureRows($signals))->toBe([
        ['doctor', 'gate.broken', 'fail', true],
        ['broken', LiveBoardSource::CONTRIBUTOR_ERRORED, 'fail', true],
        ['ledger', 'ledger.balanced', 'pass', true],
        ['ledger', 'ledger.orphans', 'fail', true],
    ])->and($signals[1]->detail)->toContain('the ledger is offline');
});

it('asks each contributor once per source', function () {
    app(BeamDoctorManifest::class)->register('acme/host', MixedReadinessAudit::class);
    config(['beam.exec.doctor_posture_ttl' => 0]);

    $source = app(BoardSource::class);
    $source->posture();
    $source->posture();

    expect(MixedReadinessAudit::$runs)->toBe(1);
});

it('reads an empty doctor manifest as no signals, not as a pass', function () {
    expect(livePosture())->toBe([]);
});
