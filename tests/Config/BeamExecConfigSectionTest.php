<?php

namespace Splicewire\Beam\Exec\Tests\Config;

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionDeclarations;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Exec\Config\BeamExecSection;

/**
 * The `beam.exec` Config Section (ticket 13, ADR-0245): BeamExecServiceProvider registers it, the declaration
 * covers every key of the shipped config/beam/exec.php, that default validates through the data-schemas
 * AcceptanceGate against the generated (nested) section schema, and every key — the flat `cards`,
 * `scenario_portfolio`, `doctor_posture_ttl` plus the nested posture/projection leaves — is declared
 * Host-tier / Deploy-effect with a clean declarations walk (no secret keys in this section).
 *
 * Runs under the base {@see \Splicewire\Beam\Exec\Tests\TestCase}, whose realm harness already boots the
 * data-schemas, frame and beam-ux providers, so {@see ConfigSectionSchema::for()} resolves the `Generator`
 * it builds the section schema from without a hand-bind.
 *
 * Coverage + acceptance run against the package's OWN shipped defaults, loaded from the file, NOT
 * `config('beam.exec')`, so a harness runtime override cannot mask the contract this certifies.
 */

/**
 * The package's shipped default config — the contract this section must cover and accept, independent of any
 * harness runtime override. Re-required per call so each test gets a fresh array.
 *
 * @return array<string, mixed>
 */
function execDefaults(): array
{
    return require dirname(__DIR__, 2).'/config/beam/exec.php';
}

it('registers beam.exec from the package service provider', function () {
    $entry = app(ConfigSectionRegistry::class)->get('beam.exec');

    expect($entry)->toBeInstanceOf(RegisteredConfigSection::class);
    expect($entry->section)->toBe(BeamExecSection::class);
    expect($entry->owner)->toBe('splicewire/laravel-beam-exec');

    // The registered package-tier defaults file must resolve to this package's OWN shipped config, so a
    // wrong packageFile cannot pass unnoticed behind an acceptance gate run against the defaults. Compare the
    // resolved real path with this package's config path, not merely the suffix.
    expect($entry->packageFile)->not->toBeNull();
    expect(is_file($entry->packageFile))->toBeTrue();
    expect(realpath($entry->packageFile))->toBe(realpath(dirname(__DIR__, 2).'/config/beam/exec.php'));
});

it('covers every key of the package default config', function () {
    $uncovered = ConfigSectionCoverage::uncovered(BeamExecSection::class, execDefaults());

    expect($uncovered)->toBe([]);
});

it('validates the package default config through the acceptance gate', function () {
    $schema = app(ConfigSectionSchema::class)->for(BeamExecSection::class);

    expect(app(AcceptanceGate::class)->accepts(execDefaults(), $schema))->toBeTrue();
});

it('declares every key Host-tier / Deploy-effect with a clean declarations walk', function () {
    // Assert the RESOLVED declaration of every shipped LEAF explicitly, as pointer => enum maps so a
    // mismatch names the offending key. posture and projection are nested container keys, so the walk
    // targets the leaf segment-paths rather than top-level keys. findings() === [] alone would still pass
    // if a non-secret key silently changed ceiling or effect, so this locks the Host / Deploy contract
    // per leaf.
    $leaves = [
        'cards' => ['cards'],
        'posture.doctor' => ['posture', 'doctor'],
        'scenario_portfolio' => ['scenario_portfolio'],
        'projection.horizon_months' => ['projection', 'horizon_months'],
        'projection.portfolio' => ['projection', 'portfolio'],
        'projection.churn_rate' => ['projection', 'churn_rate'],
        'projection.expansion_rate' => ['projection', 'expansion_rate'],
        'projection.growth_rate' => ['projection', 'growth_rate'],
        'projection.price_changes' => ['projection', 'price_changes'],
        'projection.new_logo_recurring_usd' => ['projection', 'new_logo_recurring_usd'],
        'projection.new_logo_plan' => ['projection', 'new_logo_plan'],
        'doctor_posture_ttl' => ['doctor_posture_ttl'],
    ];

    $ceilings = array_map(
        fn (array $segments): ConfigTier => BeamExecSection::keyDeclaration($segments)->ceiling,
        $leaves,
    );
    $effects = array_map(
        fn (array $segments): ConfigEffect => BeamExecSection::keyDeclaration($segments)->effect,
        $leaves,
    );

    expect($ceilings)->toBe(array_fill_keys(array_keys($leaves), ConfigTier::Host));
    expect($effects)->toBe(array_fill_keys(array_keys($leaves), ConfigEffect::Deploy));

    // The declarations walk is also clean: Host ceiling never admits the operator tier and this section
    // carries no secret keys, so no misdeclaration finding (secret-open-to-operator / secret-in-list / …).
    expect(ConfigSectionDeclarations::findings(BeamExecSection::class))->toBe([]);
});
