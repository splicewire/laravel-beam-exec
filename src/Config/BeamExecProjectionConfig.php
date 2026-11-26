<?php

namespace Splicewire\Beam\Exec\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The `beam.exec.projection` defaults (nested in {@see BeamExecSection}): the Projection lens' baseline
 * reading, held flat per party from the last closed month and run forward by beam-synthetic's projection
 * engine. Rates are monthly fractions. A `projection:<id>` lens cookie selects a portfolio whose churn and
 * expansion rates take over for that request; the other keys here still apply.
 */
class BeamExecProjectionConfig extends Data
{
    public function __construct(
        #[Title('Horizon months'), Description('How many months after the anchor (the last closed month) the lens projects forward.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public int $horizon_months,
        #[Title('Portfolio'), Description('A beam-synthetic portfolio id whose churn and expansion rates win wherever it states them (a `projection:<id>` lens cookie names one per request); null takes the churn/expansion/growth rates below.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public mixed $portfolio,
        #[Title('Churn rate'), Description('The share of paying parties lost to churn each month, 0 to 1 (1.0 loses them all in the first month); which parties go is reproducible, so revenue follows them. Overridden by the portfolio\'s rate when one is named above.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public float $churn_rate,
        #[Title('Expansion rate'), Description('Monthly expansion of retained revenue as a fraction, -1 or more (negative contracts it). Overridden by the portfolio\'s rate when one is named above.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public float $expansion_rate,
        #[Title('Growth rate'), Description('New paying parties per existing paying party per month; a value below 0 reads as 0 (no new logos from growth).'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public float $growth_rate,
        #[Title('Price changes'), Description('A schedule of list-price changes, each `[\'period\' => \'YYYY-MM\', \'rate\' => 0.10, \'plan\' => \'starter\']` (omit `plan` to apply to every plan). A change at or before the anchor is skipped, since the anchor\'s prices already carry it.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public array $price_changes,
        #[Title('New logo recurring USD'), Description('The starting monthly recurring a new party added by growth pays, before any price change in force is applied; null uses the baseline\'s average recurring revenue per paying party.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public mixed $new_logo_recurring_usd,
        #[Title('New logo plan'), Description('The plan key a new party added by growth is placed on; null places it on no plan, so only an all-plan price change (one with no `plan`) reaches it.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public mixed $new_logo_plan,
    ) {
    }
}
