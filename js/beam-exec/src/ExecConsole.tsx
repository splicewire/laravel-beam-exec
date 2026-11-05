import { Suspense, lazy, type ComponentType } from 'react';
import { BoardLensBar } from './BoardLensBar';

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

/**
 * The exec realm's console page: the lens bar above the packaged frame console. The host mounts it by naming the
 * page on the console macro (`Route::beamRealmConsole('exec', [...], page: 'exec/console')`) and registering
 * {@see execPages} with its Inertia page map. The bar reads `{basename}/lens`, where the host mounts the lens
 * operations beside the console.
 */
export function createExecConsole(resolve: ResolvePage, frameConsole = 'frame/console'): ComponentType<RealmConsoleProps> {
    const FrameConsole = lazy(async () => ({ default: await resolve(frameConsole) }));

    return function ExecConsole(props: RealmConsoleProps) {
        const base = (props.basename ?? '/exec').replace(/\/$/, '');

        return (
            <>
                <BoardLensBar url={`${base}/lens`} />
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
 * The page component is built once, so an Inertia visit back to the board does not remount the console.
 */
export function execPages(resolve: ResolvePage): Record<string, () => Promise<{ default: PageComponent }>> {
    let page: ComponentType<RealmConsoleProps> | null = null;

    return {
        'exec/console': async () => ({ default: (page ??= createExecConsole(resolve)) }),
    };
}
