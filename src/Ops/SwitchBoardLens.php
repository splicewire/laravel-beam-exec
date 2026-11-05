<?php

namespace Splicewire\Beam\Exec\Ops;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use LogicException;
use Splicewire\Beam\Exec\Board\Lens\BoardLensBar;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Data\BoardLensBarData;
use Splicewire\Beam\Exec\Data\BoardLensChoiceData;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/**
 * Choose the board's lens: the lens bar's switch. Scaffolded with `splicewire:beam:make:particle-op`.
 *
 * The body is {@see BoardLensChoiceData}; a lens the board would not honour (outside the vocabulary, or a portfolio
 * this host does not declare) is refused with a 422 before this runs. The response queues the
 * `beam_exec_lens` cookie ({@see BoardLensResolver::cookie()}), which the host's `EncryptCookies` encrypts on the way
 * out, and answers the bar as the NEXT request will read it: the chosen lens, and the banner its source calls for.
 * The response is always no-store: it sets the lens.
 *
 * Gated by `exec.view` on the entitlement plane (`abilityModel: false`), so only a board member can choose a lens.
 * Mount it beside {@see ReadBoardLens}, in the board console's `web` group:
 *
 *     Particle::ops('exec', 'exec-dashboard', [ReadBoardLens::class, SwitchBoardLens::class]);
 */
#[ParticleOp(
    resource: 'exec-dashboard',
    name: 'switch-lens',
    summary: 'Choose the board\'s lens',
    description: 'Sets the encrypted beam_exec_lens cookie to Live, Truth, Scenario or Projection (optionally naming a declared portfolio) and returns the lens bar as the next request reads it.',
    kind: OperationKind::Write,
    ability: 'exec.view',
    abilityModel: false,
    affordance: false,
    input: BoardLensChoiceData::class,
    output: BoardLensBarData::class,
    subject: NoSubject::class,
)]
class SwitchBoardLens
{
    public static function handle(mixed $model, Request $request, mixed $actor): BoardLensBarData
    {
        $input = BoardLensChoiceData::validateAndCreate($request->all());
        $resolver = app(BoardLensResolver::class);
        $lens = $resolver->choose($input->lens) ?? throw new LogicException('A validated lens choice resolves.');

        Cookie::queue($resolver->cookie($lens, $request));
        $request->attributes->set(BoardLensBar::NO_STORE, true);

        return app(BoardLensBar::class)->for($lens, $resolver->source($lens));
    }
}
