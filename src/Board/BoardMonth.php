<?php

namespace Splicewire\Beam\Exec\Board;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

/** A historical month ends at its last instant; the source's current month stays month-to-date. */
final class BoardMonth
{
    public static function vocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::exact('month')->titled('Month (YYYY-MM)'));
    }

    public static function at(BoardSource $source, array $filters): CarbonImmutable
    {
        $value = $filters['month'] ?? null;
        $asOf = $source->asOf();
        if (! is_string($value) || preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            return $asOf;
        }
        $month = CarbonImmutable::createFromFormat('!Y-m', $value, $asOf->getTimezone());

        // The source cannot attest to a later month; unknown/malformed choices retain its current reading.
        return $month->startOfMonth()->gte($asOf->startOfMonth()) ? $asOf : $month->endOfMonth();
    }
}
