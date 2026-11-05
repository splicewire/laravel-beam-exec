<?php

namespace Splicewire\Beam\Exec\Board;

use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/**
 * What a board card measures. A class implementing it, and declared with {@see BoardCard}, is one card.
 *
 * ## The one rule: read only through the source
 *
 * {@see measure()} is handed a {@see BoardSource} and must read nothing else: no model query, no `DB` call, no
 * config read of a figure. The source is what the board's lens selects (the estate live or in truth, or a
 * synthesized scenario), so a card that reads around it shows live numbers under a "Synthesized" watermark. The
 * package's conformance test measures every registered card against an in-memory source inside
 * `DB::listen` and requires zero queries.
 *
 * A metric is resolved from the container, so it may take collaborators in its constructor, provided none of them
 * is a way round the source.
 */
interface BoardMetric
{
    /**
     * The card's figures, in display order. The first figure is the card's headline.
     *
     * @return list<BoardFigureData>
     */
    public function measure(BoardSource $source): array;
}
