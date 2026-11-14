<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Carbon\CarbonImmutable;
use Schemastud\Frame\Attributes\WidgetIn;
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\FacetedBoardMetric;
use Splicewire\Beam\Exec\Board\Figures;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

/**
 * MRR month by month: one figure per month, oldest first, drawn on the dashboard as `@schemastud/frame`'s
 * `trend-card` (a line, with projected months dashed).
 *
 * Each figure is one period: its id is the month (`YYYY-MM`), its label the month's name, its value the month's
 * ending MRR as a plain number (the widget plots only numbers; a formatted amount would be a gap in the line), and
 * its `forecast` whether the month is projected ({@see Figures::forecast()}). It reads the same thirteen months as
 * {@see RevenueCard} through the same {@see RevenueMovements}, so the two cards agree on every month they share, and
 * a live source answers both from one ledger read.
 *
 * It projects. Under the Projection lens the months run up to the last projected one, so the line is measured up
 * to the anchor and dashed after it.
 */
#[BoardCard(
    key: 'mrr-trend',
    label: 'MRR trend',
    icon: 'TrendingUp',
    order: 16,
    projects: true,
)]
#[WidgetIn('summary', 'trend-card')]
final class MrrTrendCard implements FacetedBoardMetric
{
    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::exact('observation', options: 'beam_exec_observations')->titled('Measured or forecast'));
    }

    public function measureFiltered(BoardSource $source, array $filters): array
    {
        $rows = $this->measure($source);
        $kind = $filters['observation'] ?? null;
        if (! in_array($kind, ['measured', 'forecast'], true)) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (BoardFigureData $row): bool => (bool) $row->forecast === ($kind === 'forecast')));
    }

    public function measure(BoardSource $source): array
    {
        $months = RevenueCard::months($source);
        $movements = RevenueMovements::from($months, $source->revenue($months));

        return array_map(fn (string $month): BoardFigureData => new BoardFigureData(
            id: $month,
            label: __('beam-exec::messages.month_label', ['month' => CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->translatedFormat('M Y')]),
            value: $movements->mrr($month),
            forecast: Figures::forecast($source, $month),
        ), $months);
    }
}
