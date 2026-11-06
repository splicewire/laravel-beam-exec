<?php

namespace Splicewire\Beam\Exec\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Board\Lens\BoardLensBar;
use Splicewire\Beam\Exec\Ops\ReadBoardLens;
use Splicewire\Beam\Exec\Ops\SwitchBoardLens;

/**
 * The board's lens bar: which lens the board reads, the switch's segments, and the banner. The declared `output:`
 * of both {@see ReadBoardLens} and {@see SwitchBoardLens}, built by {@see BoardLensBar}.
 *
 * `$banner` is non-null exactly when the source the cards read carries a watermark: always under Scenario and
 * Projection, never under Live or Truth.
 */
#[TypeScript]
class BoardLensBarData extends BeamData
{
    /**
     * @param  string  $lens  the active lens's cookie value (`live`, `scenario:demo-mixed-book`, …)
     * @param  string  $mode  the active lens's mode (`live`, `truth`, `scenario`, `projection`)
     * @param  bool  $chosen  whether a cookie chose it (false = the board's default)
     * @param  string  $defaultMode  the mode this board reads with no lens chosen (`truth` while the reporting toggle is on)
     * @param  list<BoardLensOptionData>  $options  the switch's segments, in order
     * @param  string|null  $switchUrl  where the switch posts (the mounted {@see SwitchBoardLens}); null when unmounted
     * @param  list<BoardLensOptionData>  $scenarios  every scenario the Scenario lens can read, one per portfolio
     *                                                (`value` is `scenario:<id>`): the host's portfolio files, then the
     *                                                scenarios stored with the scenario builder
     */
    public function __construct(
        public string $lens,
        public string $mode,
        public bool $chosen,
        public string $defaultMode,
        #[DataCollectionOf(BoardLensOptionData::class)]
        public array $options,
        public ?BoardLensBannerData $banner,
        public ?string $switchUrl,
        #[DataCollectionOf(BoardLensOptionData::class)]
        public array $scenarios = [],
    ) {}
}
