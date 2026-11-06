<?php

namespace Splicewire\Beam\Exec\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Scenarios\ScenarioBuilder;
use Splicewire\Beam\Exec\Data\ScenarioCatalogData;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;

/**
 * Read the Scenarios panel: every scenario the board can show (the host's portfolio files, then the scenarios stored
 * with the builder), the blueprints the builder composes from, and whether this reader may save. Scaffolded with
 * `splicewire:beam:make:particle-op`.
 *
 * Subject-free (`NoSubject`, `abilityModel: false`) and gated by `exec.view`, the board's own gate. Mount the
 * scenario operations beside the lens operations, in the board console's `web` group, above any `{id}` route:
 *
 *     Particle::ops('exec', 'exec-dashboard', [ReadScenarios::class, PreviewScenario::class, SaveScenario::class, DeleteScenario::class]);
 */
#[ParticleOp(
    resource: 'exec-dashboard',
    name: 'scenarios',
    summary: 'Read the board\'s scenarios',
    description: 'The scenarios the board can show (declared portfolio files and scenarios stored with the builder), the blueprints a scenario is composed from, and whether the reader may save and delete scenarios.',
    kind: OperationKind::Read,
    ability: 'exec.view',
    abilityModel: false,
    affordance: false,
    input: false,
    output: ScenarioCatalogData::class,
    subject: NoSubject::class,
    method: HttpMethod::Get,
)]
class ReadScenarios
{
    public static function handle(mixed $model, Request $request, mixed $actor): ScenarioCatalogData
    {
        return app(ScenarioBuilder::class)->catalog($actor, app(BoardLens::class));
    }
}
