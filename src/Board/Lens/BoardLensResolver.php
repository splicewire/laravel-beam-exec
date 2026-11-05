<?php

namespace Splicewire\Beam\Exec\Board\Lens;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Splicewire\Beam\Commerce\Plan;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
use Splicewire\Beam\Exec\Board\Sources\ScenarioPlan;
use Splicewire\Beam\Exec\Board\Sources\SyntheticBoardSource;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
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

    private function defaultPortfolio(): ?string
    {
        $configured = $this->config->get('beam.exec.scenario_portfolio');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return array_key_first($this->portfolios->all());
    }
}
