<?php

namespace Splicewire\Beam\Exec\Board\Lens;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Splicewire\Beam\Commerce\Plan;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
use Splicewire\Beam\Exec\Board\Sources\ProjectionBoardSource;
use Splicewire\Beam\Exec\Board\Sources\ScenarioPlan;
use Splicewire\Beam\Exec\Board\Sources\SyntheticBoardSource;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Projection\PriceChange;
use Splicewire\Beam\Synthetic\Projection\ProjectionAssumptions;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;
use Splicewire\Beam\Synthetic\ScenarioExpander;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Chooses the board's lens for a request, and the {@see BoardSource} that lens reads.
 *
 * ## The lens
 *
 * The `beam_exec_lens` cookie ({@see BoardLens}) chooses it. Without a valid one the board reads the default:
 * **Truth** while beam-tenancy's reporting toggle excludes synthetic tenants ({@see SyntheticReporting::excludes()}),
 * **Live** otherwise. The toggle sets the default; it does not forbid choosing Live.
 *
 * A Scenario lens names a portfolio, or reads `beam.exec.scenario_portfolio` (null = the first portfolio the host
 * declares). A portfolio the host does not declare is an unknown value, read as the default lens, so a stale
 * cookie cannot strand the board.
 *
 * ## The source
 *
 *  - Live and Truth: {@see LiveBoardSource::live()} and {@see LiveBoardSource::truth()}.
 *  - Scenario: {@see SyntheticBoardSource}, the portfolio's roster run in memory over the months that end with
 *    today's. Building it reads the host's price list once: the plans the roster's blueprints name, so the
 *    scenario is priced by the plans live billing prices with. That one central `plans` read is the only query a
 *    Scenario board makes; every card read after it is answered in memory.
 *  - Projection: {@see ProjectionBoardSource} over the default reading (truth while the toggle excludes synthetic
 *    tenants, live otherwise), anchored on the last closed month (the month before the as-of instant). Building it
 *    reads that reading's revenue once; every card read after it is answered in memory.
 *
 * ## A projection's assumptions
 *
 * `beam.exec.projection` configures the horizon and every rate. A Projection lens may name a portfolio
 * (`projection:<id>`, else `beam.exec.projection.portfolio`): a declared portfolio's `churnRate` and `expansionRate`
 * win where it states them (owner ruling D13: the portfolio supplies optional churn and expansion rates). A
 * portfolio the host does not declare reads the default lens, as an undeclared scenario does. A configured price
 * change at or before the anchor is skipped: the anchor's prices already carry it.
 */
final class BoardLensResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly PortfolioRegistry $portfolios,
        private readonly BlueprintRegistry $blueprints,
        private readonly ScenarioExpander $expander,
        private readonly SyntheticReporting $reporting,
    ) {}

    public function resolve(?Request $request): BoardLens
    {
        $lens = BoardLens::parse($request?->cookie(BoardLens::COOKIE));

        if ($lens === null) {
            return $this->default();
        }

        if ($lens->mode === BoardLensMode::Projection) {
            $portfolio = $lens->portfolio ?? $this->projectionPortfolio();

            if ($portfolio === null) {
                return BoardLens::projection(chosen: true);
            }

            return $this->portfolios->get($portfolio) !== null ? BoardLens::projection($portfolio, chosen: true) : $this->default();
        }

        if ($lens->mode !== BoardLensMode::Scenario) {
            return $lens;
        }

        $portfolio = $lens->portfolio ?? $this->defaultPortfolio();

        return $portfolio !== null && $this->portfolios->get($portfolio) !== null
            ? BoardLens::scenario($portfolio, chosen: true)
            : $this->default();
    }

    /** The lens a request reads without choosing one. */
    public function default(): BoardLens
    {
        return $this->reporting->excludes() ? BoardLens::truth() : BoardLens::live();
    }

    public function source(BoardLens $lens, ?CarbonImmutable $asOf = null): BoardSource
    {
        return match ($lens->mode) {
            BoardLensMode::Live => LiveBoardSource::live(),
            BoardLensMode::Truth => LiveBoardSource::truth(),
            BoardLensMode::Scenario => $this->scenario($lens, $asOf ?? CarbonImmutable::now()),
            BoardLensMode::Projection => $this->projection($lens, $asOf ?? CarbonImmutable::now()),
        };
    }

    /**
     * The cookie that chooses `$lens` on the next request: encrypted by the host's `EncryptCookies`, HTTP-only and
     * same-site lax, kept for {@see BoardLens::COOKIE_MINUTES}.
     */
    public function cookie(BoardLens $lens, ?Request $request = null): Cookie
    {
        return cookie(BoardLens::COOKIE, $lens->value(), BoardLens::COOKIE_MINUTES, '/', null, $request?->isSecure(), true, false, 'lax');
    }

    /** The cookie that returns the board to its default lens. */
    public function forget(): Cookie
    {
        return cookie()->forget(BoardLens::COOKIE);
    }

    private function scenario(BoardLens $lens, CarbonImmutable $asOf): SyntheticBoardSource
    {
        $portfolio = $this->portfolios->get((string) ($lens->portfolio ?? $this->defaultPortfolio()));

        if ($portfolio === null) {
            throw new InvalidArgumentException(sprintf('The scenario portfolio `%s` is not declared on this host.', (string) $lens->portfolio));
        }

        $roster = SyntheticRoster::ofPortfolio($portfolio, $this->blueprints);

        return new SyntheticBoardSource(
            roster: $roster,
            label: $portfolio->label,
            expander: $this->expander,
            plans: $this->priceList($roster->planSlugs()),
            asOf: $asOf,
            unitsPerUsd: (float) $this->config->get('beam.synthetic.usage_units_per_usd', 1000),
        );
    }

    /**
     * The default reading's revenue at the last closed month before `$asOf`, run forward at the configured (or the
     * named portfolio's) assumptions.
     *
     * @param  BoardSource|null  $base  the reading to project; null = the default lens's ({@see default()})
     */
    public function projection(BoardLens $lens, CarbonImmutable $asOf, ?BoardSource $base = null): ProjectionBoardSource
    {
        $portfolio = null;
        $id = $lens->portfolio ?? $this->projectionPortfolio();

        if ($id !== null) {
            $portfolio = $this->portfolios->get($id) ?? throw new InvalidArgumentException(sprintf('The projection portfolio `%s` is not declared on this host.', $id));
        }

        $anchor = $asOf->startOfMonth()->subMonthNoOverflow()->format('Y-m');

        return new ProjectionBoardSource(
            base: $base ?? $this->source($this->default()),
            anchor: $anchor,
            assumptions: $this->assumptions($anchor, $portfolio),
            rates: $portfolio?->label,
        );
    }

    /** `beam.exec.projection`, with a portfolio's own churn and expansion rates where it states them. */
    public function assumptions(string $anchor, ?ScenarioPortfolioData $portfolio = null): ProjectionAssumptions
    {
        $config = (array) $this->config->get('beam.exec.projection', []);
        $rate = fn (string $key): float => (float) ($config[$key] ?? 0.0);
        $changes = [];

        foreach ((array) ($config['price_changes'] ?? []) as $change) {
            $change = (array) $change;

            if ((string) ($change['period'] ?? '') <= $anchor) {
                continue;
            }

            $changes[] = new PriceChange((string) $change['period'], (float) ($change['rate'] ?? 0.0), isset($change['plan']) ? (string) $change['plan'] : null);
        }

        return new ProjectionAssumptions(
            horizonMonths: (int) ($config['horizon_months'] ?? 6),
            churnRate: $portfolio?->churnRate ?? $rate('churn_rate'),
            expansionRate: $portfolio?->expansionRate ?? $rate('expansion_rate'),
            growthRate: $rate('growth_rate'),
            priceChanges: $changes,
            newLogoRecurringUsd: isset($config['new_logo_recurring_usd']) ? (float) $config['new_logo_recurring_usd'] : null,
            newLogoPlanKey: isset($config['new_logo_plan']) ? (string) $config['new_logo_plan'] : null,
        );
    }

    /**
     * The host's plans for `$slugs`, as plain price-list entries.
     *
     * @param  list<string>  $slugs
     * @return array<string, ScenarioPlan> slug => plan
     */
    private function priceList(array $slugs): array
    {
        $plans = [];

        foreach (Plan::query()->whereIn('slug', $slugs)->get() as $plan) {
            $plans[(string) $plan->slug] = new ScenarioPlan((string) $plan->slug, (string) $plan->name, $plan->billingComponents());
        }

        return $plans;
    }

    /** The portfolio a bare `projection` takes its rates from: `beam.exec.projection.portfolio`, or none. */
    private function projectionPortfolio(): ?string
    {
        $configured = $this->config->get('beam.exec.projection.portfolio');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    private function defaultPortfolio(): ?string
    {
        $configured = $this->config->get('beam.exec.scenario_portfolio');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return array_key_first($this->portfolios->all());
    }
}
