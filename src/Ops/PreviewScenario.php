<?php

namespace Splicewire\Beam\Exec\Ops;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Splicewire\Beam\Exec\Board\Lens\BoardLensBar;
use Splicewire\Beam\Exec\Board\Scenarios\ScenarioBuilder;
use Splicewire\Beam\Exec\Data\ScenarioDraftData;
use Splicewire\Beam\Exec\Data\ScenarioPreviewData;
use Splicewire\Beam\Exec\Localization\ExecError;
use Splicewire\Beam\Exec\Localization\ExecInvalidArgumentException;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/**
 * Preview a draft scenario: the revenue and MRR-trend figures the board would show for the portfolio in the request
 * body, computed in memory. Scaffolded with `splicewire:beam:make:particle-op`.
 *
 * A Read that takes a body, so it mounts as POST (the declared default). It writes nothing and stores nothing; the
 * only query it makes is the host's plan catalog, which prices the scenario ({@see ScenarioBuilder::preview()}). A
 * blueprint whose plan the host does not sell is a 422 naming it, not a figure priced at zero. The figures carry the
 * synthesized watermark, so the response is no-store.
 *
 * Gated by `exec.view`: anyone on the board may try a scenario; storing one takes {@see SaveScenario}.
 */
#[ParticleOp(
    resource: 'exec-dashboard',
    name: 'preview-scenario',
    summary: 'Preview a draft scenario',
    description: 'Runs a portfolio composed in the request body (blueprints with counts and start offsets, optional churn and expansion rates) through the Scenario lens in memory and returns its revenue and MRR-trend figures, watermarked as synthesized. Stores nothing.',
    kind: OperationKind::Read,
    ability: 'exec.view',
    abilityModel: false,
    affordance: false,
    input: ScenarioDraftData::class,
    output: ScenarioPreviewData::class,
    subject: NoSubject::class,
)]
class PreviewScenario
{
    public static function handle(mixed $model, Request $request, mixed $actor): ScenarioPreviewData
    {
        $draft = ScenarioDraftData::validateAndCreate($request->all());
        $request->attributes->set(BoardLensBar::NO_STORE, true);

        try {
            return app(ScenarioBuilder::class)->preview($draft);
        } catch (ExecInvalidArgumentException $e) {
            throw CodedValidationException::for('members', $e->error, $e->params);
        } catch (InvalidArgumentException $e) {
            throw CodedValidationException::for('members', ExecError::ScenarioPreviewInvalid, ['detail' => $e->getMessage()]);
        }
    }
}
