<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Commerce\Money\MoneyPresenter;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardMonth;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Board\FacetedBoardMetric;
use Splicewire\Beam\Exec\Board\Figures;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

/**
 * What the platform spends on its tenants' metered usage: the provider cost this month to date, against last
 * month's, how many tenants drove any, and which meter costs most.
 *
 * It is cost, not revenue: what a meter cost the platform, before any markup a plan charges on it. The month is the
 * month of {@see BoardSource::asOf()}, so on a live board it is a month in progress set against a closed one.
 */
#[BoardCard(
    key: 'usage',
    label: 'Usage',
    icon: 'Gauge',
    order: 35,
)]
final class UsageCard implements FacetedBoardMetric
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
        $now = BoardMonth::at($source, $filters);
        $current = $source->usage(Figures::month($now));
        $previous = $source->usage(Figures::previousMonth($now));

        $cost = fn (array $rows): float => array_sum(array_map(fn (BoardUsage $u): float => $u->costUsd, $rows));
        $thisMonth = $cost($current);
        $lastMonth = $cost($previous);

        $active = [];
        $byMeter = [];
        foreach ($current as $usage) {
            if ($usage->costUsd > 0 || $usage->quantity > 0) {
                $active[$usage->tenantId] = true;
            }
            $byMeter[$usage->meter] = ($byMeter[$usage->meter] ?? 0.0) + $usage->costUsd;
        }

        // The costliest meter; a tie reads in meter-key order, the same every time.
        uksort($byMeter, fn (string $a, string $b): int => [$byMeter[$b], $a] <=> [$byMeter[$a], $b]);
        $top = array_key_first(array_filter($byMeter, fn (float $c): bool => $c > 0));

        return [
            new BoardFigureData(
                id: 'cost-this-month',
                label: 'Usage cost, month to date',
                value: MoneyPresenter::format($thisMonth, 'cost'),
            ),
            new BoardFigureData(
                id: 'cost-last-month',
                label: 'Usage cost last month',
                value: MoneyPresenter::format($lastMonth, 'cost'),
            ),
            new BoardFigureData(
                id: 'cost-change',
                label: 'Against last month',
                value: Figures::signedPercent(Figures::change($lastMonth, $thisMonth)),
            ),
            new BoardFigureData(
                id: 'active-tenants',
                label: 'Tenants with usage',
                value: count($active),
                tone: $active === [] ? null : SummaryFigureData::TONE_ACTIVE,
            ),
            new BoardFigureData(
                id: 'top-meter',
                label: 'Costliest meter',
                value: $top ?? Figures::UNDEFINED,
            ),
        ];
    }
}
