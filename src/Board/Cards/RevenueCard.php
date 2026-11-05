<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Commerce\Money\MoneyPresenter;
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\Figures;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/**
 * Recurring revenue and how it moved: MRR and ARR now, this month's new, expansion, contraction and churned MRR,
 * logo churn, paying customers, net revenue retention over twelve months, and usage revenue beside them.
 *
 * MRR is contracted recurring revenue (the subscription fee and seat and Brokered-Tenant overage, over the seats
 * measured that month); usage priced on a meter is the separate last figure. Every figure comes from beam-commerce's
 * {@see RevenueMovements} over the source's revenue rows, so a live board and a synthesized one move by the same rules.
 *
 * It reads thirteen months, ending with the month of {@see BoardSource::asOf()}: the opening month is the base of
 * the twelve-month retention, and the months between it and last month tell a returning customer (reactivation) from
 * a new one.
 */
#[BoardCard(
    key: 'revenue',
    label: 'Revenue',
    icon: 'CircleDollarSign',
    order: 15,
)]
final class RevenueCard implements BoardMetric
{
    /** The months read: the as-of month and the twelve before it. */
    public const MONTHS = 13;

    public function measure(BoardSource $source): array
    {
        $months = self::months($source);
        $now = end($months);
        $movements = RevenueMovements::from($months, $source->revenue($months));
        $month = $movements->movement($now);
        $retention = $movements->retention($months[0], $now)->netRevenueRetention();
        $money = fn (float $usd): string => MoneyPresenter::format($usd);

        return [
            new BoardFigureData(id: 'mrr', label: 'MRR', value: $money($month->endingMrr), tone: SummaryFigureData::TONE_ACTIVE),
            new BoardFigureData(id: 'arr', label: 'ARR', value: $money($month->arr())),
            new BoardFigureData(
                id: 'net-new-mrr',
                label: 'Net new MRR this month',
                value: $money($month->netNewMrr()),
                tone: $month->netNewMrr() < 0 ? SummaryFigureData::TONE_WARN : null,
            ),
            new BoardFigureData(id: 'new-mrr', label: 'New MRR', value: $money($month->newMrr)),
            new BoardFigureData(id: 'expansion-mrr', label: 'Expansion MRR', value: $money($month->expansionMrr)),
            new BoardFigureData(id: 'reactivation-mrr', label: 'Reactivation MRR', value: $money($month->reactivationMrr)),
            new BoardFigureData(id: 'contraction-mrr', label: 'Contraction MRR', value: $money($month->contractionMrr)),
            new BoardFigureData(
                id: 'churned-mrr',
                label: 'Churned MRR',
                value: $money($month->churnedMrr),
                tone: $month->churnedMrr > 0 ? SummaryFigureData::TONE_WARN : null,
            ),
            new BoardFigureData(id: 'paying', label: 'Paying customers', value: $month->endingLogos),
            new BoardFigureData(id: 'logo-churn', label: 'Logo churn this month', value: Figures::percent($month->logoChurnRate())),
            new BoardFigureData(
                id: 'nrr-12',
                label: 'Net revenue retention, 12 months',
                value: Figures::percent($retention),
                tone: $retention === null ? null : ($retention >= 1.0 ? SummaryFigureData::TONE_ACTIVE : SummaryFigureData::TONE_WARN),
            ),
            new BoardFigureData(id: 'usage-revenue', label: 'Usage revenue this month', value: $money($month->usageUsd)),
        ];
    }

    /** @return list<string> the {@see MONTHS} months ending with the as-of month, ascending */
    public static function months(BoardSource $source): array
    {
        $end = $source->asOf()->startOfMonth();

        return array_map(
            fn (int $back): string => $end->subMonthsNoOverflow($back)->format('Y-m'),
            range(self::MONTHS - 1, 0),
        );
    }
}
