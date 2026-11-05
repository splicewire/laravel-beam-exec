/**
 * The wire shapes of the board's two lens operations, mirrored from their declared Data classes in the PHP
 * `splicewire/laravel-beam-exec` package (generated names `Splicewire.Beam.Exec.Data.*`):
 *
 *  - `GET {base}/lens` (`ReadBoardLens`) answers `{ data: BoardLensBarData }`;
 *  - `POST {base}/switch-lens` (`SwitchBoardLens`) takes `BoardLensChoiceData` and answers the same envelope.
 *
 * The PHP suite pins these keys (`tests/Board/LensBarTest.php`), so a change there is a change here.
 */

/** One segment of the switch. `value` is what the switch posts. */
export interface BoardLensOptionData {
    value: string;
    label: string;
    description: string;
    active: boolean;
    available: boolean;
}

/** The board's watermark, stated once for the page. There is no dismissal field, by design. */
export interface BoardLensBannerData {
    kind: 'synthesized' | 'projected';
    label: string;
    detail: string;
}

export interface BoardLensBarData {
    lens: string;
    mode: 'live' | 'truth' | 'scenario' | 'projection';
    chosen: boolean;
    defaultMode: string;
    options: BoardLensOptionData[];
    banner: BoardLensBannerData | null;
    switchUrl: string | null;
}

export interface BoardLensChoiceData {
    lens: string;
}
