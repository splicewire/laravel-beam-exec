<?php

namespace Splicewire\Beam\Exec\Board\Lens;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardWatermark;
use Splicewire\Beam\Exec\Data\BoardLensBannerData;
use Splicewire\Beam\Exec\Data\BoardLensBarData;
use Splicewire\Beam\Exec\Data\BoardLensOptionData;
use Splicewire\Beam\Exec\Ops\ReadBoardLens;
use Splicewire\Beam\Exec\Ops\SwitchBoardLens;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;

/**
 * The lens bar's data: the switch's four segments and the banner ({@see BoardLensBarData}), read by
 * {@see ReadBoardLens} and answered by {@see SwitchBoardLens}.
 *
 * ## The banner follows the data, not the cookie
 *
 * The banner is taken from the {@see BoardSource} the cards read, through the same two questions
 * {@see BoardWatermark::stamp()} asks of it (`synthesizedFrom()`, `projection()`), so its text and detail are the
 * leading watermark figure every card already carries. A cookie that names Scenario but resolves to the default lens
 * shows no banner, because no card is watermarked; a source that synthesizes shows one whatever the cookie says.
 *
 * ## No caching
 *
 * {@see mark()} flags the request whenever the source it read is watermarked, and
 * {@see NoStoreWatermarkedResponses} answers every flagged response `Cache-Control: no-store, private`, the shape
 * beam-ux's Preview uses: a synthesized or projected figure must never be served from a cache to a later reader
 * who chose Live.
 */
final class BoardLensBar
{
    /** The request attribute that says this response carries watermarked figures (or set a lens). */
    public const NO_STORE = 'beam.exec.lens.no-store';

    /** The route name the switch's mount takes by default (`{resourceKey}.{op}`). */
    public const SWITCH_ROUTE = 'exec-dashboard.switch-lens';

    private const OPTIONS = [
        BoardLensMode::Live->value => ['Live', 'The estate as it stands, synthetic tenants included.'],
        BoardLensMode::Truth->value => ['Truth', 'Real tenants only.'],
        BoardLensMode::Scenario->value => ['Scenario', 'A synthetic portfolio, modelled in memory.'],
        BoardLensMode::Projection->value => ['Projection', 'Last month\'s revenue held flat and run forward.'],
    ];

    public function __construct(
        private readonly BoardLensResolver $resolver,
        private readonly Router $router,
        private readonly PortfolioRegistry $portfolios,
    ) {}

    public function for(BoardLens $lens, BoardSource $source): BoardLensBarData
    {
        $options = [];

        foreach (self::OPTIONS as $value => [$label, $description]) {
            $options[] = new BoardLensOptionData(
                value: $value,
                label: $label,
                description: $description,
                active: $lens->mode->value === $value,
                available: $this->resolver->choose($value) !== null,
            );
        }

        return new BoardLensBarData(
            lens: $lens->value(),
            mode: $lens->mode->value,
            chosen: $lens->chosen,
            defaultMode: $this->resolver->default()->mode->value,
            options: $options,
            banner: self::banner($source),
            switchUrl: $this->router->has(self::SWITCH_ROUTE) ? route(self::SWITCH_ROUTE, [], false) : null,
            scenarios: $this->scenarios($lens),
        );
    }

    /**
     * One option per portfolio the Scenario lens can read, so the bar can choose `scenario:<id>` directly: the
     * declared portfolios, then the stored ones (a scenario saved with the builder is listed from the next read).
     *
     * @return list<BoardLensOptionData>
     */
    private function scenarios(BoardLens $lens): array
    {
        $options = [];

        foreach ($this->portfolios->all() as $id => $portfolio) {
            $options[] = new BoardLensOptionData(
                value: BoardLens::scenario($id)->value(),
                label: $portfolio->label,
                description: $this->portfolios->declares($id) ? 'Declared by the host.' : 'Saved with the scenario builder.',
                active: $lens->mode === BoardLensMode::Scenario && $lens->portfolio === $id,
                available: true,
            );
        }

        return $options;
    }

    /** The banner a source's figures call for: its watermark, or null for a reading of the estate. */
    public static function banner(BoardSource $source): ?BoardLensBannerData
    {
        $from = $source->synthesizedFrom();

        if ($from !== null) {
            return new BoardLensBannerData(BoardLensBannerData::SYNTHESIZED, BoardWatermark::TEXT, $from);
        }

        $projection = $source->projection();

        return $projection === null ? null : new BoardLensBannerData(BoardLensBannerData::PROJECTED, BoardWatermark::PROJECTED_TEXT, $projection->describe());
    }

    /** Flag `$request`'s response no-store when `$source` is watermarked. */
    public static function mark(?Request $request, BoardSource $source): void
    {
        if ($request !== null && ($source->synthesizedFrom() !== null || $source->projection() !== null)) {
            $request->attributes->set(self::NO_STORE, true);
        }
    }
}
