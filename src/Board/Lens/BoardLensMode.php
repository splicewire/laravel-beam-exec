<?php

namespace Splicewire\Beam\Exec\Board\Lens;

use Splicewire\Beam\Exec\Board\BoardSource;

/**
 * What a board reads, as a {@see BoardLens} names it.
 *
 *  - **Live**: the estate's central tables, synthetic tenants included.
 *  - **Truth**: the same tables, real tenants only.
 *  - **Scenario**: a synthetic portfolio, read in memory. Every figure is watermarked as synthesized.
 *
 * The value is what the lens cookie carries and what {@see BoardSource::lens()} answers.
 */
enum BoardLensMode: string
{
    case Live = 'live';
    case Truth = 'truth';
    case Scenario = 'scenario';

    /** Whether a board read under this mode is synthesized rather than read from the estate. */
    public function synthesizes(): bool
    {
        return $this === self::Scenario;
    }
}
