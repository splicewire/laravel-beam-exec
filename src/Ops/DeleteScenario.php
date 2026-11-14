<?php

namespace Splicewire\Beam\Exec\Ops;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Scenarios\ScenarioBuilder;
use Splicewire\Beam\Exec\Data\ScenarioCatalogData;
use Splicewire\Beam\Exec\Data\ScenarioIdData;
use Splicewire\Beam\Exec\Localization\ExecAccessDeniedException;
use Splicewire\Beam\Exec\Localization\ExecError;
use Splicewire\Beam\Exec\Realm\ExecRealm;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/**
 * Delete a stored scenario. Scaffolded with `splicewire:beam:make:particle-op`.
 *
 * Only a scenario stored with the builder can go; a portfolio file is refused (a 422), as is an id nothing stores.
 * Logged to the central activity trail by beam-synthetic's `StoredPortfolios`. A board whose lens cookie still names
 * the deleted scenario reads its default lens from the next request (a stale cookie never strands the board).
 *
 * Gated like {@see SaveScenario}: `exec.scenario.manage`, plus `exec.view` in the body.
 */
#[ParticleOp(
    resource: 'exec-dashboard',
    name: 'delete-scenario',
    summary: 'Delete a stored scenario',
    description: 'Deletes a scenario stored with the builder (never a declared portfolio file). Logged to the central activity trail. Answers the refreshed scenario list.',
    kind: OperationKind::Write,
    ability: 'exec.scenario.manage',
    abilityModel: false,
    affordance: false,
    input: ScenarioIdData::class,
    output: ScenarioCatalogData::class,
    subject: NoSubject::class,
)]
class DeleteScenario
{
    public static function handle(mixed $model, Request $request, mixed $actor): ScenarioCatalogData
    {
        if (! app(Gate::class)->forUser($actor)->allows('entitlement:'.ExecRealm::ENTITLEMENT)) {
            throw new ExecAccessDeniedException(ExecError::DeleteBoardSeat);
        }

        $input = ScenarioIdData::validateAndCreate($request->all());
        $builder = app(ScenarioBuilder::class);
        $builder->delete($input->id, $actor);

        return $builder->catalog($actor, app(BoardLens::class));
    }
}
