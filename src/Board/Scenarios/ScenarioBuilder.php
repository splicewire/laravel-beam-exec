<?php

namespace Splicewire\Beam\Exec\Board\Scenarios;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Board\Cards\MrrTrendCard;
use Splicewire\Beam\Exec\Board\Cards\RevenueCard;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensBar;
use Splicewire\Beam\Exec\Board\Lens\BoardLensMode;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Board\Sources\SyntheticBoardSource;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Data\ScenarioBlueprintData;
use Splicewire\Beam\Exec\Data\ScenarioCatalogData;
use Splicewire\Beam\Exec\Data\ScenarioDraftData;
use Splicewire\Beam\Exec\Data\ScenarioEntryData;
use Splicewire\Beam\Exec\Data\ScenarioPreviewData;
use Splicewire\Beam\Exec\Data\ScenarioSaveData;
use Splicewire\Beam\Exec\Ops\DeleteScenario;
use Splicewire\Beam\Exec\Ops\PreviewScenario;
use Splicewire\Beam\Exec\Ops\ReadScenarios;
use Splicewire\Beam\Exec\Ops\SaveScenario;
use Splicewire\Beam\Exec\Realm\ExecRealm;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;
use Splicewire\Beam\Synthetic\Store\StoredPortfolios;

/**
 * The scenario builder behind the Scenarios panel: list what the board can show, preview a draft, store or delete a
 * scenario. Its four operations ({@see ReadScenarios}, {@see PreviewScenario}, {@see SaveScenario},
 * {@see DeleteScenario}) are thin over it.
 *
 * ## Where a scenario lives
 *
 * A scenario IS a beam-synthetic portfolio. The host declares some as files (`beam.synthetic.portfolio_paths`); a
 * board member stores others at runtime, in beam-synthetic's {@see StoredPortfolios}. {@see PortfolioRegistry} reads
 * both, so a stored scenario is immediately a Scenario lens (`scenario:<id>`), a `synthetic:project --portfolio` and
 * a `synthetic:materialize --portfolio`, with no second code path. A declared id always wins.
 *
 * ## Previewing reads one table
 *
 * {@see preview()} builds the same {@see SyntheticBoardSource} the Scenario lens reads, over the draft held in
 * memory, then measures the revenue and MRR-trend cards on it. Building it reads the host's plans (the one central
 * `plans` read every Scenario makes, exec-board 16); nothing else is read, and the measuring is in memory. The
 * figures carry the synthesized watermark, like every figure a scenario produces.
 */
final class ScenarioBuilder
{
    /** The id a draft is previewed under. It is never stored, and it seeds nothing: a member's curve seeds on its blueprint. */
    public const DRAFT_ID = 'draft';

    /** The route names the four operations take by default (`{resourceKey}.{op}`). */
    public const ROUTES = [
        'preview' => 'exec-dashboard.preview-scenario',
        'save' => 'exec-dashboard.save-scenario',
        'delete' => 'exec-dashboard.delete-scenario',
    ];

    public function __construct(
        private readonly PortfolioRegistry $portfolios,
        private readonly BlueprintRegistry $blueprints,
        private readonly StoredPortfolios $stored,
        private readonly BoardLensResolver $resolver,
        private readonly Router $router,
        private readonly Gate $gate,
    ) {}

    public function catalog(mixed $actor, ?BoardLens $lens = null): ScenarioCatalogData
    {
        $scenarios = [];

        foreach ($this->portfolios->all() as $portfolio) {
            $scenarios[] = $this->entry($portfolio, $lens);
        }

        $blueprints = array_values(array_map(fn ($blueprint): ScenarioBlueprintData => new ScenarioBlueprintData(
            id: $blueprint->id,
            name: $blueprint->name,
            planSlug: $blueprint->planSlug,
            scenario: $blueprint->scenario->value,
            windowMonths: $blueprint->windowMonths,
        ), $this->blueprints->all()));

        return new ScenarioCatalogData(
            scenarios: $scenarios,
            blueprints: $blueprints,
            canManage: self::mayManage($this->gate, $actor),
            storeAvailable: $this->stored->available(),
            previewUrl: $this->url(self::ROUTES['preview']),
            saveUrl: $this->url(self::ROUTES['save']),
            deleteUrl: $this->url(self::ROUTES['delete']),
            switchUrl: $this->url(BoardLensBar::SWITCH_ROUTE),
        );
    }

    /**
     * What `$draft` would show on the board this month: its revenue and MRR-trend figures, watermarked.
     *
     * @throws \InvalidArgumentException when a blueprint's plan is not in the host's catalog (named), or the draft
     *                                   names a blueprint the host does not declare
     */
    public function preview(ScenarioDraftData $draft, ?CarbonImmutable $asOf = null): ScenarioPreviewData
    {
        $source = $this->resolver->scenarioFor($draft->toPortfolio(self::DRAFT_ID), $asOf ?? CarbonImmutable::now());

        return new ScenarioPreviewData(
            label: $draft->label,
            asOf: $source->asOf()->format('Y-m'),
            months: $this->windowLength($draft),
            tenants: count($source->tenants()),
            revenue: $this->summary(new RevenueCard, $source, 'revenue'),
            trend: $this->summary(new MrrTrendCard, $source, 'mrr-trend'),
        );
    }

    /** Store `$input` (replacing a stored scenario under its id), logged centrally by the store. */
    public function save(ScenarioSaveData $input, mixed $actor): ScenarioPortfolioData
    {
        return $this->stored->save($input->toPortfolio($input->id), $actor instanceof Model ? $actor : null);
    }

    public function delete(string $id, mixed $actor): bool
    {
        return $this->stored->delete($id, $actor instanceof Model ? $actor : null);
    }

    /** Whether `$actor` may save and delete scenarios: `exec.view` AND `exec.scenario.manage`. */
    public static function mayManage(Gate $gate, mixed $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        $as = $gate->forUser($actor);

        return $as->allows('entitlement:'.ExecRealm::ENTITLEMENT) && $as->allows('entitlement:'.ExecRealm::SCENARIO_MANAGE);
    }

    private function entry(ScenarioPortfolioData $portfolio, ?BoardLens $lens): ScenarioEntryData
    {
        $value = BoardLens::scenario($portfolio->id)->value();

        return new ScenarioEntryData(
            id: $portfolio->id,
            label: $portfolio->label,
            lens: $value,
            source: $this->portfolios->declares($portfolio->id) ? ScenarioEntryData::FILE : ScenarioEntryData::STORED,
            members: $portfolio->members,
            churnRate: $portfolio->churnRate,
            expansionRate: $portfolio->expansionRate,
            active: $lens !== null && $lens->mode === BoardLensMode::Scenario && $lens->portfolio === $portfolio->id,
        );
    }

    /**
     * A card's figures on the draft's source, watermarked the way {@see BoardCardRegistry::figures()}
     * stamps them, as summary figures.
     *
     * @return list<SummaryFigureData>
     */
    private function summary(RevenueCard|MrrTrendCard $card, SyntheticBoardSource $source, string $key): array
    {
        return array_map(
            fn (BoardFigureData $figure) => $figure->toSummaryFigure(),
            BoardWatermark::stamp($card->measure($source), $source, $key),
        );
    }

    private function windowLength(ScenarioDraftData $draft): int
    {
        return SyntheticRoster::ofPortfolio($draft->toPortfolio(self::DRAFT_ID), $this->blueprints)->length();
    }

    private function url(string $route): ?string
    {
        return $this->router->has($route) ? route($route, [], false) : null;
    }
}
