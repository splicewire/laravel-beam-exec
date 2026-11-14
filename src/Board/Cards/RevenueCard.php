<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Carbon\CarbonImmutable;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Commerce\Money\MoneyPresenter;
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardMonth;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\FacetedBoardMetric;
use Splicewire\Beam\Exec\Board\Figures;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

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
 *
 * It projects: under the Projection lens the as-of month is the last projected month, so every figure describes a
 * projected month and is marked a forecast ({@see Figures::forecast()}); at horizon 0 they describe the anchor and
 * are marked measured. Its month-by-month MRR is {@see MrrTrendCard}.
 */
#[BoardCard(
    key: 'revenue',
    label: 'Revenue',
    icon: 'CircleDollarSign',
    order: 15,
    projects: true,
)]
final class RevenueCard implements FacetedBoardMetric
{
    /** The months read: the as-of month and the twelve before it. */
    public const MONTHS = 13;

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
        $months = self::months($source, BoardMonth::at($source, $filters));
        $now = end($months);
        $movements = RevenueMovements::from($months, $source->revenue($months));
        $month = $movements->movement($now);
        $retention = $movements->retention($months[0], $now)->netRevenueRetention();
        $money = fn (float $usd): string => MoneyPresenter::format($usd);
        $forecast = Figures::forecast($source, $now);

        $figures = [
            new BoardFigureData(id: 'mrr', label: __('beam-exec::messages.labels_mrr'), value: $money($month->endingMrr), tone: SummaryFigureData::TONE_ACTIVE),
            new BoardFigureData(id: 'arr', label: __('beam-exec::messages.labels_arr'), value: $money($month->arr())),
            new BoardFigureData(
                id: 'net-new-mrr',
                label: __('beam-exec::messages.labels_net_new_mrr_this_month'),
                value: $money($month->netNewMrr()),
                tone: $month->netNewMrr() < 0 ? SummaryFigureData::TONE_WARN : null,
            ),
            new BoardFigureData(id: 'new-mrr', label: __('beam-exec::messages.labels_new_mrr'), value: $money($month->newMrr)),
            new BoardFigureData(id: 'expansion-mrr', label: __('beam-exec::messages.labels_expansion_mrr'), value: $money($month->expansionMrr)),
            new BoardFigureData(id: 'reactivation-mrr', label: __('beam-exec::messages.labels_reactivation_mrr'), value: $money($month->reactivationMrr)),
            new BoardFigureData(id: 'contraction-mrr', label: __('beam-exec::messages.labels_contraction_mrr'), value: $money($month->contractionMrr)),
            new BoardFigureData(
                id: 'churned-mrr',
                label: __('beam-exec::messages.labels_churned_mrr'),
                value: $money($month->churnedMrr),
                tone: $month->churnedMrr > 0 ? SummaryFigureData::TONE_WARN : null,
            ),
            new BoardFigureData(id: 'paying', label: __('beam-exec::messages.labels_paying_customers'), value: $month->endingLogos),
            new BoardFigureData(id: 'logo-churn', label: __('beam-exec::messages.labels_logo_churn_this_month'), value: Figures::percent($month->logoChurnRate())),
            new BoardFigureData(
                id: 'nrr-12',
                label: __('beam-exec::messages.labels_net_revenue_retention_12_months'),
                value: Figures::percent($retention),
                tone: $retention === null ? null : ($retention >= 1.0 ? SummaryFigureData::TONE_ACTIVE : SummaryFigureData::TONE_WARN),
            ),
            new BoardFigureData(id: 'usage-revenue', label: __('beam-exec::messages.labels_usage_revenue_this_month'), value: $money($month->usageUsd)),
        ];

        return $forecast === null ? $figures : array_map(fn (BoardFigureData $figure): BoardFigureData => new BoardFigureData(
            id: $figure->id,
            label: $figure->label,
            value: $figure->value,
            tone: $figure->tone,
            forecast: $forecast,
        ), $figures);
    }

    /** @return list<string> the {@see MONTHS} months ending with the as-of month, ascending */
    public static function months(BoardSource $source, ?CarbonImmutable $asOf = null): array
    {
        $end = ($asOf ?? $source->asOf())->startOfMonth();

        return array_map(
            fn (int $back): string => $end->subMonthsNoOverflow($back)->format('Y-m'),
            range(self::MONTHS - 1, 0),
        );
    }
}
