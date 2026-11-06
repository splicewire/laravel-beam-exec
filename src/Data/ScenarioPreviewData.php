<?php

namespace Splicewire\Beam\Exec\Data;

use Schemastud\Frame\Data\SummaryFigureData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Board\Cards\MrrTrendCard;
use Splicewire\Beam\Exec\Board\Cards\RevenueCard;
use Splicewire\Beam\Exec\Ops\PreviewScenario;

/**
 * What a draft scenario would show on the board: the declared `output:` of {@see PreviewScenario}.
 *
 * `revenue` and `trend` are the {@see RevenueCard} and {@see MrrTrendCard} figures under that scenario, as summary
 * figures (the wire every card's dashboard summary sends, so `trend` draws as frame's `trend-card`). Both lead with the
 * synthesized watermark figure, as every scenario figure does.
 */
#[TypeScript]
class ScenarioPreviewData extends BeamData
{
    /**
     * @param  string  $asOf  the month the scenario's window ends with (`YYYY-MM`)
     * @param  int  $months  how many months the window spans
     * @param  int  $tenants  how many synthetic tenants the roster holds
     * @param  list<SummaryFigureData>  $revenue
     * @param  list<SummaryFigureData>  $trend
     */
    public function __construct(
        public string $label,
        public string $asOf,
        public int $months,
        public int $tenants,
        #[DataCollectionOf(SummaryFigureData::class)]
        public array $revenue,
        #[DataCollectionOf(SummaryFigureData::class)]
        public array $trend,
    ) {}
}
