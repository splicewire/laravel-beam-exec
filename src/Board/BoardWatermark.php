<?php

namespace Splicewire\Beam\Exec\Board;

use LogicException;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/**
 * The synthesized watermark: "Synthesized — not real data", on every figure a synthesized source produces.
 *
 * It is stamped in the data, not drawn by a renderer, so no surface can show a synthesized figure unmarked:
 *
 *  - every figure is flagged `synthesized: true`;
 *  - the card leads with one more figure, `synthesized`, labelled with the watermark text and valued with what the
 *    figures were synthesized from, toned `warn`. It survives the mapping to frame's summary figures (which have no
 *    flag of their own), so a dashboard renderer that knows nothing of lenses still prints the note on each card.
 *
 * It is not configurable (owner ruling D12). {@see BoardCardRegistry::figures()} applies it to every card on the way
 * out, so a card cannot leave it off, and a card that measures nothing stays empty rather than becoming a lone
 * watermark. The figure id `synthesized` is reserved: a card that emits it is refused.
 */
final class BoardWatermark
{
    public const TEXT = 'Synthesized — not real data';

    /** The reserved figure id of the watermark's own figure. */
    public const FIGURE = 'synthesized';

    /**
     * @param  list<BoardFigureData>  $figures
     * @return list<BoardFigureData>
     */
    public static function stamp(array $figures, BoardSource $source, string $card): array
    {
        foreach ($figures as $figure) {
            if ($figure->id === self::FIGURE) {
                throw new LogicException(sprintf('The board card `%s` emits the figure id `%s`, which the synthesized watermark reserves.', $card, self::FIGURE));
            }
        }

        $from = $source->synthesizedFrom();

        if ($from === null || $figures === []) {
            return array_values($figures);
        }

        $stamped = [new BoardFigureData(id: self::FIGURE, label: self::TEXT, value: $from, tone: SummaryFigureData::TONE_WARN, synthesized: true)];

        foreach ($figures as $figure) {
            $stamped[] = new BoardFigureData(id: $figure->id, label: $figure->label, value: $figure->value, tone: $figure->tone, synthesized: true);
        }

        return $stamped;
    }
}
