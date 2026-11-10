import { Suspense, lazy, useState, type ComponentType } from 'react';
import { BoardLensBar } from './BoardLensBar';
import { ScenarioPanel, type TrendWidget } from './ScenarioPanel';

/** The three props `Route::beamRealmConsole()` renders its page with. */
export interface RealmConsoleProps {
    realm?: string;
    basename?: string;
    manifestUrl?: string;
}

// A page component takes whatever props its route renders it with.
type PageComponent = ComponentType<any>;

/** A host's page resolver, e.g. `@splicewire/beam-inertia`'s `resolveBeamPage`. */
export type ResolvePage = (name: string) => Promise<PageComponent>;

export interface ExecConsoleOptions {
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
export function createExecConsole(resolve: ResolvePage, options: ExecConsoleOptions | string = {}): ComponentType<RealmConsoleProps> {
    const { frameConsole = 'frame/console', trendCard, scenarios = true } = typeof options === 'string' ? { frameConsole: options } : options;
    const FrameConsole = lazy(async () => ({ default: await resolve(frameConsole) }));

    return function ExecConsole(props: RealmConsoleProps) {
        const base = (props.basename ?? '/exec').replace(/\/$/, '');
        const [version, setVersion] = useState(0);

        return (
            <>
                <BoardLensBar url={`${base}/lens`} version={version} />
                {scenarios && <ScenarioPanel url={`${base}/scenarios`} trendCard={trendCard} onChanged={() => setVersion((v) => v + 1)} />}
                <Suspense fallback={null}>
                    <FrameConsole {...props} />
                </Suspense>
            </>
        );
    };
}

/**
 * The page map to spread into the host's Inertia pages, the `beamDocsPages` shape:
 *
 *     import { resolveBeamPage } from '@splicewire/beam-inertia';
 *     import { execPages } from '@splicewire/beam-exec';
 *     beamInertiaOptions({ pages: { ...execPages(resolveBeamPage), ...pages } });
 *
 * To draw the scenario preview with the board's own trend-card, pass frame's widget:
 *
 *     import { TrendCard } from '@schemastud/frame/cards';
 *     execPages(resolveBeamPage, { trendCard: TrendCard })
 *
 * The page component is built once, so an Inertia visit back to the board does not remount the console.
 */
export function execPages(resolve: ResolvePage, options: ExecConsoleOptions = {}): Record<string, () => Promise<{ default: PageComponent }>> {
    let page: ComponentType<RealmConsoleProps> | null = null;

    return {
        'exec/console': async () => ({ default: (page ??= createExecConsole(resolve, options)) }),
    };
}
