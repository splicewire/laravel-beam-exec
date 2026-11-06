/**
 * The wire shapes of the board's lens and scenario operations, mirrored from their declared Data classes in the PHP
 * `splicewire/laravel-beam-exec` package (generated names `Splicewire.Beam.Exec.Data.*`):
 *
 *  - `GET {base}/lens` (`ReadBoardLens`) answers `{ data: BoardLensBarData }`;
 *  - `POST {base}/switch-lens` (`SwitchBoardLens`) takes `BoardLensChoiceData` and answers the same envelope;
 *  - `GET {base}/scenarios` (`ReadScenarios`) answers `{ data: ScenarioCatalogData }`, and so do
 *    `POST {base}/save-scenario` (`SaveScenario`, body `ScenarioSaveData`) and `POST {base}/delete-scenario`
 *    (`DeleteScenario`, body `ScenarioIdData`);
 *  - `POST {base}/preview-scenario` (`PreviewScenario`) takes `ScenarioDraftData` and answers `{ data: ScenarioPreviewData }`.
 *
 * The PHP suite pins these keys (`tests/Board/LensBarTest.php`, `tests/Board/ScenarioBuilderTest.php`), so a change
 * there is a change here.
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
    /** One option per scenario the Scenario lens can read; `value` is `scenario:<id>`. Absent from an older server. */
    scenarios?: BoardLensOptionData[];
}

export interface BoardLensChoiceData {
    lens: string;
}

/** One member of a scenario: a blueprint, how many tenants read from it, and from which month. */
export interface PortfolioMemberData {
    blueprint: string;
    count: number;
    startOffsetMonths: number;
}

/** A scenario as the builder composes it, before it has an id. */
export interface ScenarioDraftData {
    label: string;
    members: PortfolioMemberData[];
    churnRate: number | null;
    expansionRate: number | null;
}

/** A draft and the id it is stored under (the lens value becomes `scenario:<id>`). */
export interface ScenarioSaveData extends ScenarioDraftData {
    id: string;
}

export interface ScenarioIdData {
    id: string;
}

/** One scenario the board can show. `source` is `file` (declared by the host) or `stored` (saved with the builder). */
export interface ScenarioEntryData {
    id: string;
    label: string;
    lens: string;
    source: 'file' | 'stored';
    members: PortfolioMemberData[];
    churnRate: number | null;
    expansionRate: number | null;
    active: boolean;
}

/** One blueprint the builder can pick. */
export interface ScenarioBlueprintData {
    id: string;
    name: string;
    planSlug: string;
    scenario: string;
    windowMonths: number;
}

export interface ScenarioCatalogData {
    scenarios: ScenarioEntryData[];
    blueprints: ScenarioBlueprintData[];
    canManage: boolean;
    storeAvailable: boolean;
    previewUrl: string | null;
    saveUrl: string | null;
    deleteUrl: string | null;
    switchUrl: string | null;
}

/** A summary figure (`@schemastud/frame`'s `SummaryFigureData`): what a card's dashboard summary sends. */
export interface ScenarioFigure {
    key: string;
    label: string;
    value: number | string;
    tone?: string | null;
    forecast?: boolean | null;
}

export interface ScenarioPreviewData {
    label: string;
    asOf: string;
    months: number;
    tenants: number;
    /** The revenue card's figures, led by the synthesized watermark figure. */
    revenue: ScenarioFigure[];
    /** The MRR-trend card's figures (one per month), led by the synthesized watermark figure. */
    trend: ScenarioFigure[];
}
