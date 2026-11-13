<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Carbon\CarbonImmutable;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardMonth;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\FacetedBoardMetric;
use Splicewire\Beam\Exec\Board\Figures;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

/**
 * How fast the tenant base is growing: this month against the base it started the month with, the year against
 * the base of a year ago, and the arrivals behind both.
 *
 * Growth is measured from creation dates, as of {@see BoardSource::asOf()}. The base at an instant is every tenant
 * created on or before it; a tenant whose creation date the source does not know is counted in every base, so it
 * never reads as an arrival. Paying customers, and the logos that leave, are the revenue card's: a tenant base only
 * grows.
 */
#[BoardCard(
    key: 'growth',
    label: 'Growth',
    icon: 'TrendingUp',
    order: 20,
)]
final class GrowthCard implements FacetedBoardMetric
{
    public function filterVocabulary(): FilterVocabulary
    {
        return BoardMonth::vocabulary();
    }

    public function measure(BoardSource $source): array
    {
        return $this->measureFiltered($source, []);
    }

    public function measureFiltered(BoardSource $source, array $filters): array
    {
        $tenants = $source->tenants();
        $now = BoardMonth::at($source, $filters);
        $monthStart = $now->startOfMonth();
        $lastMonthStart = $monthStart->subMonth();
        $yearAgo = $now->subYear();

        $baseAt = fn (CarbonImmutable $at): int => count(array_filter(
            $tenants,
            fn (BoardTenant $t): bool => $t->createdAt === null || $t->createdAt->lte($at),
        ));
        $arrivedIn = fn (CarbonImmutable $from, CarbonImmutable $to): int => count(array_filter(
            $tenants,
            fn (BoardTenant $t): bool => $t->createdAt !== null && $t->createdAt->gt($from) && $t->createdAt->lte($to),
        ));

        // "Before the month began" is strictly before its first instant.
        $startOfMonthBase = $baseAt($monthStart->subMicrosecond());
        $current = $baseAt($now);
        $growth = Figures::change($startOfMonthBase, $current);

        return [
            new BoardFigureData(
                id: 'month-growth',
                label: 'Growth this month',
                value: Figures::signedPercent($growth),
                tone: ($growth ?? 0.0) > 0 ? SummaryFigureData::TONE_ACTIVE : null,
            ),
            new BoardFigureData(
                id: 'new-this-month',
                label: 'New this month',
                value: $arrivedIn($monthStart->subMicrosecond(), $now),
                tone: SummaryFigureData::TONE_BUSY,
            ),
            new BoardFigureData(
                id: 'new-last-month',
                label: 'New last month',
                value: $arrivedIn($lastMonthStart->subMicrosecond(), $monthStart->subMicrosecond()),
            ),
            new BoardFigureData(
                id: 'new-12-months',
                label: 'New in the last 12 months',
                value: $arrivedIn($yearAgo, $now),
            ),
            new BoardFigureData(
                id: 'year-growth',
                label: 'Growth over 12 months',
                value: Figures::signedPercent(Figures::change($baseAt($yearAgo), $current)),
            ),
        ];
    }
}
