<?php

namespace Splicewire\Beam\Exec\Board;

use LogicException;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/**
 * The board's two watermarks, on every figure a source produces that is not read from the estate:
 *
 *  - **synthesized**, "Synthesized — not real data", when the source synthesizes its figures from a scenario
 *    ({@see BoardSource::synthesizedFrom()});
 *  - **projected**, "Projected — not real data", when the source projects the estate forward
 *    ({@see BoardSource::projection()}). A projection starts from real revenue, so it is not synthesized; it is
 *    still not a measurement, and it is marked as distinctly as a scenario is.
 *
 * Either is stamped in the data, not drawn by a renderer, so no surface can show such a figure unmarked:
 *
 *  - every figure is flagged (`synthesized: true` or `projected: true`);
 *  - the card leads with one more figure, `synthesized` or `projected`, labelled with the watermark text and valued
 *    with what the figures came from, toned `warn`. It survives the mapping to frame's summary figures (which carry
 *    neither flag), so a dashboard renderer that knows nothing of lenses still prints the note on each card. It is
 *    not a period: its `forecast` is null, so a series widget's forecast boundary still falls on the last measured
 *    month.
 *
 * Neither is configurable (owner ruling D12). {@see BoardCardRegistry::figures()} applies them to every card on the
 * way out, so a card cannot leave them off, and a card that measures nothing stays empty rather than becoming a
 * lone watermark. The figure ids `synthesized` and `projected` are reserved: a card that emits one is refused.
 */
final class BoardWatermark
{
    public const TEXT = 'Synthesized — not real data';

    /** The reserved figure id of the synthesized watermark's own figure. */
    public const FIGURE = 'synthesized';

    public const PROJECTED_TEXT = 'Projected — not real data';

    /** The reserved figure id of the projected watermark's own figure. */
    public const PROJECTED_FIGURE = 'projected';

    /**
     * @param  list<BoardFigureData>  $figures
     * @return list<BoardFigureData>
     */
    public static function stamp(array $figures, BoardSource $source, string $card): array
    {
        foreach ($figures as $figure) {
            if ($figure->id === self::FIGURE || $figure->id === self::PROJECTED_FIGURE) {
                throw new LogicException(sprintf('The board card `%s` emits the figure id `%s`, which the board\'s watermarks reserve.', $card, $figure->id));
            }
        }

        if ($figures === []) {
            return [];
        }

        $from = $source->synthesizedFrom();

        if ($from !== null) {
            return [
                new BoardFigureData(id: self::FIGURE, label: self::TEXT, value: $from, tone: SummaryFigureData::TONE_WARN, synthesized: true),
                ...array_map(fn (BoardFigureData $figure): BoardFigureData => $figure->marked(synthesized: true), array_values($figures)),
            ];
        }

        $projection = $source->projection();

        if ($projection !== null) {
            return [
                new BoardFigureData(id: self::PROJECTED_FIGURE, label: self::PROJECTED_TEXT, value: $projection->describe(), tone: SummaryFigureData::TONE_WARN, projected: true),
                ...array_map(fn (BoardFigureData $figure): BoardFigureData => $figure->marked(projected: true), array_values($figures)),
            ];
        }

        return array_values($figures);
    }
}
