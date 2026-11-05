<?php

namespace Splicewire\Beam\Exec\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Board\BoardWatermark;

/**
 * The lens bar's banner: the board's watermark ({@see BoardWatermark}), stated once for the whole page.
 *
 * It is built from the source the cards read, not from the cookie, so it is present exactly when the cards carry a
 * watermark. It has no dismissal: there is no field to hide it, and the renderer draws no close control (owner
 * ruling D12, "mandatory and not configurable").
 *
 * @see BoardLensBarData
 */
#[TypeScript]
class BoardLensBannerData extends BeamData
{
    public const SYNTHESIZED = 'synthesized';

    public const PROJECTED = 'projected';

    /**
     * @param  string  $kind  `synthesized` or `projected`, the id of the watermark figure every card leads with
     * @param  string  $label  the watermark text ("Synthesized — not real data", "Projected — not real data")
     * @param  string  $detail  what the figures came from: the scenario's name, or what was projected
     */
    public function __construct(
        public string $kind,
        public string $label,
        public string $detail,
    ) {}
}
