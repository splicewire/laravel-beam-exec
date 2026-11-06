<?php

namespace Splicewire\Beam\Exec\Ops;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Scenarios\ScenarioBuilder;
use Splicewire\Beam\Exec\Data\ScenarioCatalogData;
use Splicewire\Beam\Exec\Data\ScenarioSaveData;
use Splicewire\Beam\Exec\Realm\ExecRealm;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Store a scenario under an id, so the board can show it as `scenario:<id>` from the next request. Scaffolded with
 * `splicewire:beam:make:particle-op`.
 *
 * The id may not be one the host declares in a portfolio file (a 422); an id already stored is replaced. The write
 * goes through beam-synthetic's `StoredPortfolios`, which validates the portfolio again and logs it to the central
 * activity trail (`synthetic-portfolios`). Answers the refreshed panel.
 *
 * Gated by `exec.scenario.manage` on the entitlement plane AND by `exec.view` (checked in the body): a stored scenario
 * is shared board state, so a read seat cannot write it. beam-accounts composes `exec.scenario.manage` from the
 * `operator` grant.
 */
#[ParticleOp(
    resource: 'exec-dashboard',
    name: 'save-scenario',
    summary: 'Store a scenario',
    description: 'Stores a portfolio (blueprints with counts and start offsets, optional churn and expansion rates) under a kebab-case id that no portfolio file declares, replacing a stored one with that id. Logged to the central activity trail. The board can then show it as scenario:<id>.',
    kind: OperationKind::Write,
    ability: 'exec.scenario.manage',
    abilityModel: false,
    affordance: false,
    input: ScenarioSaveData::class,
    output: ScenarioCatalogData::class,
    subject: NoSubject::class,
)]
class SaveScenario
{
    public static function handle(mixed $model, Request $request, mixed $actor): ScenarioCatalogData
    {
        if (! app(Gate::class)->forUser($actor)->allows('entitlement:'.ExecRealm::ENTITLEMENT)) {
            throw new AccessDeniedHttpException('Saving a scenario needs a board seat (exec.view).');
        }

        $input = ScenarioSaveData::validateAndCreate($request->all());
        $builder = app(ScenarioBuilder::class);

        try {
            $builder->save($input, $actor);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['id' => $e->getMessage()]);
        }

        return $builder->catalog($actor, app(BoardLens::class));
    }
}
