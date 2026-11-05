<?php

/*
 * This package's own `extra.package-topology`, checked against its own runtime `require`.
 *
 * Why not `AssertsDeclaredTopology` here: `DeclaredContractSource` reads only VENDORED manifests, and a
 * package does not vendor itself, so in this repo that assertion runs over zero of this package's rules
 * and passes by not looking (measured at splicewire/laravel-beam-market; see
 * splicewire/laravel-beam-dev's `OwnTopologyDeclarationTest`). The graph-level check runs where this
 * package IS vendored: a host's `DeclaredTopologyTest`.
 *
 * `require-dev` is out of scope, matching the tool: its graph is runtime `require` only.
 */

const EXEC_FORBIDDEN = ['splicewire/tower', 'splicewire/laravel-satellite', 'splicewire/laravel-tower-*'];
const EXEC_REQUIRED = [
    'splicewire/laravel-beam',
    'splicewire/laravel-beam-ux',
    'splicewire/laravel-beam-tenancy',
    'splicewire/laravel-beam-commerce',
    'splicewire/laravel-beam-synthetic',
];

function execManifest(): array
{
    $path = __DIR__.'/../../composer.json';
    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBeArray("Could not read {$path}");

    return $decoded;
}

function execTopology(): array
{
    $topology = execManifest()['extra']['package-topology'] ?? null;

    // A missing declaration must FAIL. "No rules" and "no violations" are otherwise the same green.
    expect($topology)->toBeArray('extra.package-topology is absent from composer.json');

    return $topology;
}

function execMatchesAny(string $package, array $globs): bool
{
    foreach ($globs as $glob) {
        if (fnmatch($glob, $package)) {
            return true;
        }
    }

    return false;
}

it('declares the fences, pinned so narrowing one is a failing test rather than a quiet edit', function () {
    $topology = execTopology();

    expect($topology['mustNotRequire'] ?? null)->toBe(EXEC_FORBIDDEN)
        ->and($topology['mustRequire'] ?? null)->toBe(EXEC_REQUIRED)
        ->and($topology['because'] ?? '')->not->toBe('');
});

it('requires every package it declares it must require, synthetic among them (D9)', function () {
    $requires = array_keys(execManifest()['require'] ?? []);

    expect(array_values(array_diff(execTopology()['mustRequire'], $requires)))->toBe([])
        ->and($requires)->toContain('splicewire/laravel-beam-synthetic');
});

it('requires nothing its own mustNotRequire forbids', function () {
    $requires = array_keys(execManifest()['require'] ?? []);

    expect($requires)->not->toBeEmpty('No runtime requires read; the manifest probe is broken.');

    $violations = array_values(array_filter(
        $requires,
        fn (string $p): bool => execMatchesAny($p, execTopology()['mustNotRequire']),
    ));

    expect($violations)->toBe([]);
});

it('would catch a forbidden require, and does not forbid the legal ones', function () {
    $globs = execTopology()['mustNotRequire'];

    foreach (['splicewire/tower', 'splicewire/laravel-satellite', 'splicewire/laravel-tower-market'] as $planted) {
        expect(execMatchesAny($planted, $globs))->toBeTrue("A require on {$planted} would NOT be caught.");
    }

    foreach ([...EXEC_REQUIRED, 'rushing/php-popcorn', 'spatie/laravel-package-tools'] as $legal) {
        expect(execMatchesAny($legal, $globs))->toBeFalse("The fences wrongly forbid {$legal}.");
    }
});
