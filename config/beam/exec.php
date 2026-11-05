<?php

// splicewire/laravel-beam-exec, merged under `beam.exec.*`.
//
// Each key lands with the code that reads it, in the same commit, so no key exists without a reader.

use Splicewire\Beam\Exec\Board\Cards\GrowthCard;
use Splicewire\Beam\Exec\Board\Cards\MrrTrendCard;
use Splicewire\Beam\Exec\Board\Cards\PlanMixCard;
use Splicewire\Beam\Exec\Board\Cards\PostureCard;
use Splicewire\Beam\Exec\Board\Cards\RevenueCard;
use Splicewire\Beam\Exec\Board\Cards\RiskCard;
use Splicewire\Beam\Exec\Board\Cards\TenantsCard;
use Splicewire\Beam\Exec\Board\Cards\UsageCard;
use Splicewire\Beam\Exec\Posture\Contributors\DoctorPostureContributor;

return [

    // The board's cards: a list of BoardMetric classes, each declared with #[BoardCard], read into
    // BoardCardRegistry (`beam.exec.cards`). Each becomes an `exec-{key}` resource on the board. The default is
    // this package's built-in cards; a host that declares its own list replaces it, so list them too to keep them.
    'cards' => [
        TenantsCard::class,
        RevenueCard::class,
        MrrTrendCard::class,
        GrowthCard::class,
        PlanMixCard::class,
        UsageCard::class,
        PostureCard::class,
        RiskCard::class,
    ],

    // Where the posture and risk cards' signals come from: a map of key to PostureContributor class, read into
    // PostureRegistry (`beam.exec.posture`). The default is the host's readiness audits. A package adds its own
    // contributor from its provider; a host that declares its own map replaces this one, so name `doctor` to keep it.
    'posture' => [
        'doctor' => DoctorPostureContributor::class,
    ],

    // The portfolio a Scenario lens reads when the lens cookie names none (`scenario` rather than
    // `scenario:<id>`): a portfolio id from beam-synthetic's PortfolioRegistry (`beam.synthetic.portfolios`).
    // null reads the first portfolio the host declares.
    'scenario_portfolio' => null,

    // The Projection lens: the default reading's revenue at the last closed month, held flat per party and run
    // forward by beam-synthetic's projection engine. Rates are monthly fractions; every key may be left out.
    //  - horizon_months: how many months after the anchor are projected.
    //  - portfolio: a beam.synthetic portfolio id whose churnRate/expansionRate win where it states them (a lens
    //    cookie `projection:<id>` names one per request); null takes the rates below.
    //  - churn_rate (0 to 1), expansion_rate (-1 or more), growth_rate (new parties per paying party; below 0 reads 0).
    //  - price_changes: a list of ['period' => 'YYYY-MM', 'rate' => 0.10, 'plan' => 'starter' or omitted for every
    //    plan]; one at or before the anchor is skipped, since the anchor's prices already carry it.
    //  - new_logo_recurring_usd / new_logo_plan: what a new party pays and is on; null = the baseline's average.
    'projection' => [
        'horizon_months' => 6,
        'portfolio' => null,
        'churn_rate' => 0.0,
        'expansion_rate' => 0.0,
        'growth_rate' => 0.0,
        'price_changes' => [],
        'new_logo_recurring_usd' => null,
        'new_logo_plan' => null,
    ],

    // Seconds a doctor posture report stays fresh. A board read never runs the readiness audits: it serves the last
    // report with its age, and queues a refresh (RefreshDoctorPosture) when there is none or it is older than this.
    // Before the first report lands the posture reads Unmeasured. `splicewire:beam:exec:refresh-posture` refreshes it
    // now (a schedule or a deploy step). 0 runs every audit inline on every read, which can take a minute.
    'doctor_posture_ttl' => 900,

];
