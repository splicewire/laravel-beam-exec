<?php

namespace Splicewire\Beam\Exec\Data;

use Schemastud\Frame\Data\SummaryFigureData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Board\BoardCardBacking;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Board\BoardWatermark;

/**
 * One figure on a board card: the read projection of every `exec-{card}` resource.
 *
 * A {@see BoardMetric} returns a list of these. The same list is drawn twice, so the two cannot disagree:
 *
 *  - as the card's rows, on the card's own page ({@see BoardCardBacking});
 *  - as the dashboard card's figures, mapped one for one to frame's {@see SummaryFigureData}.
 *
 * `$tone` is frame's closed tone vocabulary ({@see SummaryFigureData::TONES}); null is neutral.
 *
 * Under a synthesized lens every figure is `synthesized`, and the card leads with the watermark's own figure
 * ({@see BoardWatermark}). Frame's summary figure has no such flag, so on the dashboard the watermark figure is
 * what carries the mark.
 */
#[TypeScript]
class BoardFigureData extends BeamData
{
    /**
     * @param  string  $id  the figure's key, stable within its card (`total`, `active`, …); the row id on the card's page
     * @param  string  $label  the figure's display label
     * @param  int|float|string  $value  a count, an amount, or an already-formatted string
     * @param  string|null  $tone  one of {@see SummaryFigureData::TONES}; null = neutral
     * @param  bool  $synthesized  whether the figure was synthesized rather than read from the estate. Set only by the
     *                             synthesized watermark ({@see BoardWatermark}); a card never sets it
     */
    public function __construct(
        public string $id,
        public string $label,
        public int|float|string $value,
        public ?string $tone = null,
        public bool $synthesized = false,
    ) {}

    public function toSummaryFigure(): SummaryFigureData
    {
        return new SummaryFigureData(key: $this->id, label: $this->label, value: $this->value, tone: $this->tone);
    }
}
