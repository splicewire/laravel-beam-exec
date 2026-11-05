<?php

namespace Splicewire\Beam\Exec\Board;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Exec\Board\Sources\ProjectionBoardSource;

/**
 * What a projecting {@see BoardSource} projects: from which reading, from which month, and how far.
 *
 * The anchor is the last month read from the estate. Every month after it, up to and including {@see $through}, is
 * projected: a card marks a figure for one of those months as a forecast ({@see forecasts()}), and the watermark
 * marks every figure the source produces as projected ({@see BoardWatermark}).
 *
 * {@see ProjectionBoardSource} answers one; every other source answers null.
 */
final class BoardProjection
{
    /**
     * @param  string  $anchor  the last measured month, `YYYY-MM`
     * @param  string  $through  the last projected month, `YYYY-MM` (the anchor itself at horizon 0)
     * @param  string  $basis  the reading the baseline was taken from: `live` or `truth`
     * @param  string|null  $rates  the portfolio whose rates the projection runs at, by label; null = the configured rates
     */
    public function __construct(
        public readonly string $anchor,
        public readonly string $through,
        public readonly string $basis,
        public readonly ?string $rates = null,
    ) {}

    /** Whether `$month` (`YYYY-MM`) is projected rather than measured. */
    public function forecasts(string $month): bool
    {
        return $month > $this->anchor;
    }

    /** How many months are projected. */
    public function horizon(): int
    {
        $anchor = self::month($this->anchor);
        $through = self::month($this->through);

        return ($through->year - $anchor->year) * 12 + $through->month - $anchor->month;
    }

    /**
     * The watermark's value: what the figures were projected from, in words.
     * "Live, October 2026 held flat, 6 months ahead" or "… at Demo mixed book's rates".
     */
    public function describe(): string
    {
        $months = $this->horizon();

        return sprintf(
            '%s, %s held flat, %d %s ahead%s',
            ucfirst($this->basis),
            self::month($this->anchor)->format('F Y'),
            $months,
            $months === 1 ? 'month' : 'months',
            $this->rates === null ? '' : " at {$this->rates}'s rates",
        );
    }

    private static function month(string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month);
    }
}
