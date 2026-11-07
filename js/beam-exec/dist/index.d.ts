import * as react from 'react';
import { ComponentType } from 'react';

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
interface BoardLensOptionData {
    value: string;
    label: string;
    description: string;
    active: boolean;
    available: boolean;
}
/** The board's watermark, stated once for the page. There is no dismissal field, by design. */
interface BoardLensBannerData {
    kind: 'synthesized' | 'projected';
    label: string;
    detail: string;
}
interface BoardLensBarData {
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
interface BoardLensChoiceData {
    lens: string;
}
/** One member of a scenario: a blueprint, how many tenants read from it, and from which month. */
interface PortfolioMemberData {
    blueprint: string;
    count: number;
    startOffsetMonths: number;
}
/** A scenario as the builder composes it, before it has an id. */
interface ScenarioDraftData {
    label: string;
    members: PortfolioMemberData[];
    churnRate: number | null;
    expansionRate: number | null;
}
/** A draft and the id it is stored under (the lens value becomes `scenario:<id>`). */
interface ScenarioSaveData extends ScenarioDraftData {
    id: string;
}
interface ScenarioIdData {
    id: string;
}
/** One scenario the board can show. `source` is `file` (declared by the host) or `stored` (saved with the builder). */
interface ScenarioEntryData {
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
interface ScenarioBlueprintData {
    id: string;
    name: string;
    planSlug: string;
    scenario: string;
    windowMonths: number;
}
interface ScenarioCatalogData {
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
interface ScenarioFigure {
    key: string;
    label: string;
    value: number | string;
    tone?: string | null;
    forecast?: boolean | null;
}
interface ScenarioPreviewData {
    label: string;
    asOf: string;
    months: number;
    tenants: number;
    /** The revenue card's figures, led by the synthesized watermark figure. */
    revenue: ScenarioFigure[];
    /** The MRR-trend card's figures (one per month), led by the synthesized watermark figure. */
    trend: ScenarioFigure[];
}

/**
 * How the bar talks to the server. The default reads the realm's own lens operations with the browser's session
 * cookie, sending Laravel's `XSRF-TOKEN` back as `X-XSRF-TOKEN` on the switch.
 */
interface BoardLensClient {
    read(url: string): Promise<BoardLensBarData>;
    choose(url: string, lens: string): Promise<BoardLensBarData>;
}
interface BoardLensBarProps {
    /** Where the bar is read (`ReadBoardLens`). Defaults to `/exec/lens`. */
    url?: string;
    /** After a lens is chosen. Defaults to reloading the page, so every card is read again under the new lens. */
    onSwitched?: (bar: BoardLensBarData) => void;
    client?: BoardLensClient;
    /** Bump it to read the bar again, e.g. after a scenario is saved, so the new one is listed at once. */
    version?: number;
}
/**
 * The board's lens bar: a segmented switch over Live, Truth, Scenario and Projection, and, under a synthesized or
 * projected lens, a banner that cannot be dismissed.
 *
 * The banner is the server's `banner`, which the server takes from the source the cards read (their watermark), not
 * from the cookie. It renders no close control and no state hides it: while the board's figures are synthesized or
 * projected, the banner is on the page.
 */
declare function BoardLensBar({ url, onSwitched, client, version }: BoardLensBarProps): react.JSX.Element | null;
/** The undismissable banner. No close control, by design (owner ruling D12: mandatory and not configurable). */
declare function LensBanner({ banner }: {
    banner: BoardLensBannerData;
}): react.JSX.Element;
/** The default client: same-origin fetch, JSON, with Laravel's XSRF cookie echoed on the write. */
declare const fetchClient: BoardLensClient;

/** How the panel talks to the server. The default is same-origin fetch over the declared scenario operations. */
interface ScenarioClient {
    catalog(url: string): Promise<ScenarioCatalogData>;
    preview(url: string, draft: ScenarioDraftData): Promise<ScenarioPreviewData>;
    save(url: string, scenario: ScenarioDraftData & {
        id: string;
    }): Promise<ScenarioCatalogData>;
    remove(url: string, id: string): Promise<ScenarioCatalogData>;
    show(url: string, lens: string): Promise<BoardLensBarData>;
}
declare const scenarioFetchClient: ScenarioClient;
/**
 * The trend widget the preview draws its MRR series with: `@schemastud/frame`'s `TrendCard` fits (`value` is a summary
 * payload). The host passes it in, so this package needs no frame dependency; without one the series is a table.
 */
type TrendWidget = ComponentType<{
    value: {
        key: string;
        label: string;
        figures: ScenarioFigure[];
    };
}>;
interface ScenarioPanelProps {
    /** Where the panel is read (`ReadScenarios`). Defaults to `/exec/scenarios`. */
    url?: string;
    client?: ScenarioClient;
    trendCard?: TrendWidget;
    /** After a scenario is saved or deleted: the lens bar reads again, so its scenario list is current. */
    onChanged?: () => void;
    /** After "Show on board" chose a lens. Defaults to reloading the page, as the lens bar does. */
    onShown?: (bar: BoardLensBarData) => void;
    /** Whether the panel starts open. */
    defaultOpen?: boolean;
    /** How long the composer waits after an edit before previewing, in milliseconds. */
    previewDelay?: number;
}
/**
 * The board's Scenarios panel: every scenario the board can show (the host's portfolio files, then the scenarios
 * stored with the builder), a composer that previews a draft live, and "Save as scenario", after which the scenario is
 * a lens (`scenario:<id>`) the bar can choose at once.
 *
 * Everything it draws comes from the declared operations; nothing is computed here. A preview's figures carry the
 * synthesized watermark the server stamps, and the composer says so.
 */
declare function ScenarioPanel({ url, client, trendCard: Trend, onChanged, onShown, defaultOpen, previewDelay, }: ScenarioPanelProps): react.JSX.Element;

/** The three props `Route::beamRealmConsole()` renders its page with. */
interface RealmConsoleProps {
    realm?: string;
    basename?: string;
    manifestUrl?: string;
}
type PageComponent = ComponentType<any>;
/** A host's page resolver, e.g. `@splicewire/beam-inertia`'s `resolveBeamPage`. */
type ResolvePage = (name: string) => Promise<PageComponent>;
interface ExecConsoleOptions {
    /** The packaged console page the board wraps. Defaults to `frame/console`. */
    frameConsole?: string;
    /**
     * The widget the scenario preview draws its MRR series with: pass `@schemastud/frame`'s `TrendCard` to draw the
     * board's own trend-card. Without one the series is a table.
     */
    trendCard?: TrendWidget;
    /** Set false to leave the Scenarios panel out (the lens bar still lists every scenario). */
    scenarios?: boolean;
}
/**
 * The exec realm's console page: the lens bar and the Scenarios panel above the packaged frame console. The host
 * mounts it by naming the page on the console macro (`Route::beamRealmConsole('exec', [...], page: 'exec/console')`)
 * and registering {@see execPages} with its Inertia page map. The bar reads `{basename}/lens` and the panel
 * `{basename}/scenarios`, where the host mounts the lens and scenario operations beside the console. Saving or
 * deleting a scenario makes the bar read again, so the new scenario is in its list at once.
 */
declare function createExecConsole(resolve: ResolvePage, options?: ExecConsoleOptions | string): ComponentType<RealmConsoleProps>;
/**
 * The page map to spread into the host's Inertia pages, the `beamDocsPages` shape:
 *
 *     import { resolveBeamPage } from '@splicewire/beam-inertia';
 *     import { execPages } from '@splicewire/beam-exec';
 *     beamInertiaOptions({ pages: { ...execPages(resolveBeamPage), ...pages } });
 *
 * To draw the scenario preview with the board's own trend-card, pass frame's widget:
 *
 *     import { TrendCard } from '@schemastud/frame';
 *     execPages(resolveBeamPage, { trendCard: TrendCard })
 *
 * The page component is built once, so an Inertia visit back to the board does not remount the console.
 */
declare function execPages(resolve: ResolvePage, options?: ExecConsoleOptions): Record<string, () => Promise<{
    default: PageComponent;
}>>;

/**
 * Same-origin JSON over the board's declared operations: the browser's session cookie, and Laravel's `XSRF-TOKEN`
 * cookie echoed as `X-XSRF-TOKEN` on a write. Every operation answers `{ data: … }`; a refusal throws a
 * {@see RequestError} carrying the status and, on a 422, the field messages.
 */
declare class RequestError extends Error {
    readonly status: number;
    readonly errors: Record<string, string[]>;
    constructor(message: string, status: number, errors?: Record<string, string[]>);
}

export { type BoardLensBannerData, BoardLensBar, type BoardLensBarData, type BoardLensBarProps, type BoardLensChoiceData, type BoardLensClient, type BoardLensOptionData, type ExecConsoleOptions, LensBanner, type PortfolioMemberData, type RealmConsoleProps, RequestError, type ResolvePage, type ScenarioBlueprintData, type ScenarioCatalogData, type ScenarioClient, type ScenarioDraftData, type ScenarioEntryData, type ScenarioFigure, type ScenarioIdData, ScenarioPanel, type ScenarioPanelProps, type ScenarioPreviewData, type ScenarioSaveData, type TrendWidget, createExecConsole, execPages, fetchClient, scenarioFetchClient };
