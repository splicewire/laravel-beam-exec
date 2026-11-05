<?php

namespace Splicewire\Beam\Exec\Data;

use Schemastud\Frame\Data\SummaryFigureData;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Board\BoardCardBacking;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Board\Figures;

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
 * Under a synthesized lens every figure is `synthesized`; under a projecting one every figure is `projected`. Either
 * way the card leads with the watermark's own figure ({@see BoardWatermark}). Frame's summary figure has neither
 * flag, so on the dashboard the watermark figure is what carries the mark.
 *
 * `$forecast` is per period, and the card sets it: true for a figure that draws a projected month, false for one
 * that draws a measured month under a projecting lens, null when no month is projected. It is the one flag that
 * reaches frame's summary figure, where a series widget (`trend-card`) draws a forecast period dashed. Null is not
 * sent there at all, so a summary under any other lens keeps the bytes it had.
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
     * @param  bool|null  $forecast  whether the figure draws a projected month (true) or a measured one (false) under a
     *                               projecting lens; null when the source projects nothing. Set by the card
     *                               ({@see Figures::forecast()})
     * @param  bool  $projected  whether the figure was drawn from a projecting source. Set only by the projected
     *                           watermark ({@see BoardWatermark}); a card never sets it
     */
    public function __construct(
        public string $id,
        public string $label,
        public int|float|string $value,
        public ?string $tone = null,
        public bool $synthesized = false,
        public ?bool $forecast = null,
        public bool $projected = false,
    ) {}

    /** The same figure, with the watermark's flags set and everything the card measured kept. */
    public function marked(bool $synthesized = false, bool $projected = false): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
            tone: $this->tone,
            synthesized: $synthesized,
            forecast: $this->forecast,
            projected: $projected,
        );
    }

    public function toSummaryFigure(): SummaryFigureData
    {
        return new SummaryFigureData(
            key: $this->id,
            label: $this->label,
            value: $this->value,
            tone: $this->tone,
            forecast: $this->forecast ?? new Optional,
        );
    }
}
