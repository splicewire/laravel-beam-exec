<?php

namespace Splicewire\Beam\Exec\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensBar;
use Splicewire\Beam\Exec\Data\BoardLensBarData;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;

/**
 * Read the board's lens bar: the lens this request reads, the switch's segments, and the banner. Scaffolded with
 * `splicewire:beam:make:particle-op`.
 *
 * The banner comes from the {@see BoardSource} this request's cards read ({@see BoardLensBar}), so it is present
 * exactly when every card carries a watermark. The response is no-store whenever it is.
 *
 * `subject: NoSubject::class`: the lens is a fact about the request, not about a record, so the op mounts at
 * `{uri}/lens` with no `{id}`. Gated by `exec.view` on the entitlement plane (`abilityModel: false`), the board's own
 * gate. Mount both lens ops in the same group as the board's console, which must be a `web` group (the lens cookie
 * is encrypted by the host's `EncryptCookies`):
 *
 *     Particle::ops('exec', 'exec-dashboard', [ReadBoardLens::class, SwitchBoardLens::class]);
 */
#[ParticleOp(
    resource: 'exec-dashboard',
    name: 'lens',
    summary: 'Read the board\'s lens bar',
    description: 'The lens the board reads (Live, Truth, Scenario or Projection), the switch\'s segments, and the synthesized or projected banner the cards\' watermark calls for.',
    kind: OperationKind::Read,
    ability: 'exec.view',
    abilityModel: false,
    affordance: false,
    input: false,
    output: BoardLensBarData::class,
    subject: NoSubject::class,
    method: HttpMethod::Get,
)]
class ReadBoardLens
{
    public static function handle(mixed $model, Request $request, mixed $actor): BoardLensBarData
    {
        $source = app(BoardSource::class);
        BoardLensBar::mark($request, $source);

        return app(BoardLensBar::class)->for(app(BoardLens::class), $source);
    }
}
