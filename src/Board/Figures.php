<?php

namespace Splicewire\Beam\Exec\Board;

use Carbon\CarbonImmutable;

/**
 * The small arithmetic and wording the built-in cards share, so "growth" and "this month" mean the same thing on
 * every card. Pure: no clock, no config, no query.
 */
final class Figures
{
    /** What a rate prints when it has no base to be a rate of (a change from zero). */
    public const UNDEFINED = '—';

    /** The change from `$from` to `$to` as a fraction of `$from`; null when `$from` is zero. */
    public static function change(float $from, float $to): ?float
    {
        return $from == 0.0 ? null : ($to - $from) / $from;
    }

    /** `$part` as a fraction of `$whole`; null when `$whole` is zero. */
    public static function share(float $part, float $whole): ?float
    {
        return $whole == 0.0 ? null : $part / $whole;
    }

    /** A fraction as a signed percentage to one decimal (`+12.5%`, `-3.0%`, `0.0%`), or {@see UNDEFINED}. */
    public static function signedPercent(?float $fraction): string
    {
        if ($fraction === null) {
            return self::UNDEFINED;
        }

        $percent = round($fraction * 100, 1);

        return ($percent > 0 ? '+' : '').number_format($percent, 1).'%';
    }

    /** A fraction as a percentage to one decimal (`40.0%`), or {@see UNDEFINED}. */
    public static function percent(?float $fraction): string
    {
        return $fraction === null ? self::UNDEFINED : number_format(round($fraction * 100, 1), 1).'%';
    }

    /** The calendar month of `$at`, as `YYYY-MM`. */
    public static function month(CarbonImmutable $at): string
    {
        return $at->format('Y-m');
    }

    /** The calendar month before the month of `$at`, as `YYYY-MM`. */
    public static function previousMonth(CarbonImmutable $at): string
    {
        return $at->startOfMonth()->subMonth()->format('Y-m');
    }
}
