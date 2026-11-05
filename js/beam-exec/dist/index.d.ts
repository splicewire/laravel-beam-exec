import * as react from 'react';
import { ComponentType } from 'react';

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
}
interface BoardLensChoiceData {
    lens: string;
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
}
/**
 * The board's lens bar: a segmented switch over Live, Truth, Scenario and Projection, and, under a synthesized or
 * projected lens, a banner that cannot be dismissed.
 *
 * The banner is the server's `banner`, which the server takes from the source the cards read (their watermark), not
 * from the cookie. It renders no close control and no state hides it: while the board's figures are synthesized or
 * projected, the banner is on the page.
 */
declare function BoardLensBar({ url, onSwitched, client }: BoardLensBarProps): react.JSX.Element | null;
/** The undismissable banner. No close control, by design (owner ruling D12: mandatory and not configurable). */
declare function LensBanner({ banner }: {
    banner: BoardLensBannerData;
}): react.JSX.Element;
/** The default client: same-origin fetch, JSON, with Laravel's XSRF cookie echoed on the write. */
declare const fetchClient: BoardLensClient;

/** The three props `Route::beamRealmConsole()` renders its page with. */
interface RealmConsoleProps {
    realm?: string;
    basename?: string;
    manifestUrl?: string;
}
type PageComponent = ComponentType<any>;
/** A host's page resolver, e.g. `@splicewire/beam-inertia`'s `resolveBeamPage`. */
type ResolvePage = (name: string) => Promise<PageComponent>;
/**
 * The exec realm's console page: the lens bar above the packaged frame console. The host mounts it by naming the
 * page on the console macro (`Route::beamRealmConsole('exec', [...], page: 'exec/console')`) and registering
 * {@see execPages} with its Inertia page map. The bar reads `{basename}/lens`, where the host mounts the lens
 * operations beside the console.
 */
declare function createExecConsole(resolve: ResolvePage, frameConsole?: string): ComponentType<RealmConsoleProps>;
/**
 * The page map to spread into the host's Inertia pages, the `beamDocsPages` shape:
 *
 *     import { resolveBeamPage } from '@splicewire/beam-inertia';
 *     import { execPages } from '@splicewire/beam-exec';
 *     beamInertiaOptions({ pages: { ...execPages(resolveBeamPage), ...pages } });
 *
 * The page component is built once, so an Inertia visit back to the board does not remount the console.
 */
declare function execPages(resolve: ResolvePage): Record<string, () => Promise<{
    default: PageComponent;
}>>;

export { type BoardLensBannerData, BoardLensBar, type BoardLensBarData, type BoardLensBarProps, type BoardLensChoiceData, type BoardLensClient, type BoardLensOptionData, LensBanner, type RealmConsoleProps, type ResolvePage, createExecConsole, execPages, fetchClient };
