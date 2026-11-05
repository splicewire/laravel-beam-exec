<?php

namespace Splicewire\Beam\Exec\Board\Sources;

use Carbon\CarbonImmutable;
use LogicException;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardProjection;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\Lens\BoardLensMode;
use Splicewire\Beam\Synthetic\Projection\ProjectionAssumptions;
use Splicewire\Beam\Synthetic\Projection\ProjectionBaseline;
use Splicewire\Beam\Synthetic\Projection\ProjectionEngine;
use Splicewire\Beam\Synthetic\Projection\ProjectionMonth;
use Splicewire\Beam\Synthetic\Projection\RevenueProjection;

/**
 * The board's revenue projected forward: the Projection lens's source. It decorates the reading the board would
 * otherwise show (live or truth) and feeds beam-synthetic's pure {@see ProjectionEngine} from it.
 *
 * ## One read, then memory
 *
 * Building the source makes the one read it ever makes: the base reading's revenue for the anchor month and the
 * {@see HISTORY_MONTHS} months before it, in a single {@see BoardSource::revenue()} call. The anchor month's rows
 * are the baseline ({@see ProjectionBaseline::fromRows()}): each party's last closed month, held flat. The engine
 * runs the baseline forward over the horizon, and every read after that is answered in memory.
 *
 * ## What each read is
 *
 * - **As of**: the last projected month (its last instant). "This month" on a projecting card is that month, so a
 *   card reading the months that end with it reads measured months up to the anchor and projected ones after.
 * - **Revenue**: the base reading's rows for a month up to the anchor, the engine's rows for a month after it, and
 *   nothing after the last projected month. A month before the history window is refused, naming the window,
 *   rather than answered empty: an empty month would read as a book with no revenue.
 * - **Tenants, subscriptions, usage, posture**: refused. The projection models revenue only, so the card registry
 *   measures only the cards that declare they project ({@see BoardCard::$projects}); a card that declares it and
 *   reads something else fails loudly rather than drawing a present-day fact under a forecast.
 *
 * The projected rows go to the same {@see RevenueMovements} the measured ones do, so a projected MRR moves by the
 * same rules as a measured one.
 */
final class ProjectionBoardSource implements BoardSource
{
    /** The months read before the anchor: enough for a card reading thirteen months to end on any projected month. */
    public const HISTORY_MONTHS = 12;

    private readonly RevenueProjection $projection;

    private readonly BoardProjection $describes;

    /** @var array<string, list<PartyPeriodRevenue>> month => the base reading's rows, from the history window */
    private array $measured = [];

    /** @var array<string, list<PartyPeriodRevenue>> month => the engine's rows */
    private array $projected = [];

    private readonly string $from;

    /**
     * @param  BoardSource  $base  the reading the baseline is taken from (live or truth); read once, here
     * @param  string  $anchor  the last measured month, `YYYY-MM`: the base reading's last closed month
     * @param  string|null  $rates  the label of the portfolio the assumptions' rates came from, for the watermark
     */
    public function __construct(
        BoardSource $base,
        string $anchor,
        ProjectionAssumptions $assumptions,
        ?string $rates = null,
        ProjectionEngine $engine = new ProjectionEngine,
    ) {
        ProjectionMonth::assert($anchor, 'projection anchor');

        $this->from = ProjectionMonth::after($anchor, -self::HISTORY_MONTHS);
        $history = array_map(fn (int $n): string => ProjectionMonth::after($this->from, $n), range(0, self::HISTORY_MONTHS));

        foreach ($history as $month) {
            $this->measured[$month] = [];
        }

        foreach ($base->revenue($history) as $row) {
            if (isset($this->measured[$row->period])) {
                $this->measured[$row->period][] = $row;
            }
        }

        $this->projection = $engine->project(ProjectionBaseline::fromRows($anchor, $this->measured[$anchor]), $assumptions);

        foreach ($this->projection->projectedPeriods() as $month) {
            $this->projected[$month] = [];
        }

        foreach ($this->projection->projectedRows() as $row) {
            $this->projected[$row->period][] = $row;
        }

        $periods = $this->projection->periods();
        $this->describes = new BoardProjection($anchor, end($periods), $base->lens(), $rates);
    }

    /** The engine's whole result: the anchor's baseline rows and the projected rows, as beam-synthetic returns it. */
    public function revenueProjection(): RevenueProjection
    {
        return $this->projection;
    }

    public function lens(): string
    {
        return BoardLensMode::Projection->value;
    }

    /** A projection is not synthesized: its baseline is the estate's own revenue. It is marked as projected instead. */
    public function synthesizedFrom(): ?string
    {
        return null;
    }

    public function projection(): BoardProjection
    {
        return $this->describes;
    }

    public function asOf(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->describes->through)->endOfMonth();
    }

    public function revenue(array $months): array
    {
        $rows = [];

        foreach ($months as $month) {
            if (isset($this->projected[$month])) {
                array_push($rows, ...$this->projected[$month]);
            } elseif (isset($this->measured[$month])) {
                array_push($rows, ...$this->measured[$month]);
            } elseif ($month < $this->from) {
                throw new LogicException(sprintf(
                    'The projection reads revenue from %s to %s; %s is before the months it read from the %s reading.',
                    $this->from,
                    $this->describes->through,
                    $month,
                    $this->describes->basis,
                ));
            }
        }

        return $rows;
    }

    public function tenants(): array
    {
        $this->notProjected('tenants');
    }

    public function subscriptions(): array
    {
        $this->notProjected('subscriptions');
    }

    public function usage(string $month): array
    {
        $this->notProjected('usage');
    }

    public function posture(): array
    {
        $this->notProjected('posture');
    }

    private function notProjected(string $read): never
    {
        throw new LogicException(sprintf(
            'The Projection lens projects revenue only, so it cannot answer %s(). A card that reads it does not project: leave `projects` off its #[BoardCard].',
            $read,
        ));
    }
}
