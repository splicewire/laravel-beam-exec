<?php

namespace Splicewire\Beam\Exec\Board\Lens;

use Splicewire\Beam\Exec\Board\BoardSource;

/**
 * What a board reads, as a {@see BoardLens} names it.
 *
 *  - **Live**: the estate's central tables, synthetic tenants included.
 *  - **Truth**: the same tables, real tenants only.
 *  - **Scenario**: a synthetic portfolio, read in memory. Every figure is watermarked as synthesized.
 *  - **Projection**: the default reading's revenue at the last closed month, held flat and run forward by
 *    beam-synthetic's projection engine. Every figure is watermarked as projected, and each projected month is
 *    marked as a forecast.
 *
 * The value is what the lens cookie carries and what {@see BoardSource::lens()} answers.
 */
enum BoardLensMode: string
{
    case Live = 'live';
    case Truth = 'truth';
    case Scenario = 'scenario';
    case Projection = 'projection';

    /** Whether a board read under this mode is synthesized rather than read from the estate. */
    public function synthesizes(): bool
    {
        return $this === self::Scenario;
    }

    /** Whether a board read under this mode is projected forward from the estate rather than read from it. */
    public function projects(): bool
    {
        return $this === self::Projection;
    }

    /** Whether the cookie may name a portfolio after the mode: a scenario's book, or a projection's rates. */
    public function takesPortfolio(): bool
    {
        return $this === self::Scenario || $this === self::Projection;
    }
}
