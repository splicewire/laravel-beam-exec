import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { getJson, message, postJson } from './http';
import type { BoardLensBannerData, BoardLensBarData } from './types';

/**
 * How the bar talks to the server. The default reads the realm's own lens operations with the browser's session
 * cookie, sending Laravel's `XSRF-TOKEN` back as `X-XSRF-TOKEN` on the switch.
 */
export interface BoardLensClient {
    read(url: string): Promise<BoardLensBarData>;
    choose(url: string, lens: string): Promise<BoardLensBarData>;
}

export interface BoardLensBarProps {
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
export function BoardLensBar({ url = '/exec/lens', onSwitched = reloadPage, client = fetchClient, version = 0 }: BoardLensBarProps) {
    const [bar, setBar] = useState<BoardLensBarData | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [pending, setPending] = useState<string | null>(null);
    const group = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let live = true;
        client.read(url).then(
            (read) => live && setBar(read),
            (error: unknown) => live && setFailed(message(error)),
        );

        return () => {
            live = false;
        };
    }, [client, url, version]);

    const choose = useCallback(
        (value: string) => {
            if (!bar?.switchUrl || pending !== null || value === bar.mode || value === bar.lens) {
                return;
            }

            setPending(value);
            client.choose(bar.switchUrl, value).then(
                (next) => {
                    setBar(next);
                    setPending(null);
                    onSwitched(next);
                },
                (error: unknown) => {
                    setPending(null);
                    setFailed(message(error));
                },
            );
        },
        [bar, client, onSwitched, pending],
    );

    // Arrow keys move between the available segments, the radio-group pattern.
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (!bar || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
        }

        const available = bar.options.filter((option) => option.available);
        const at = available.findIndex((option) => option.active);
        const step = event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1;
        const next = available[(at + step + available.length) % available.length];

        if (next) {
            event.preventDefault();
            group.current?.querySelector<HTMLButtonElement>(`[data-lens="${next.value}"]`)?.focus();
            choose(next.value);
        }
    };

    if (!bar) {
        return failed ? (
            <div data-exec-lens-bar="failed" className="px-6 py-2 text-xs text-muted-foreground">
                The board's lens did not load: {failed}
            </div>
        ) : null;
    }

    return (
        <div data-exec-lens-bar={bar.mode} className="sticky top-0 z-30 border-b border-border bg-background/95 backdrop-blur">
            <div className="flex flex-wrap items-center gap-3 px-6 py-2">
                <span id="exec-lens-label" className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Lens
                </span>
                <div
                    ref={group}
                    role="radiogroup"
                    aria-labelledby="exec-lens-label"
                    onKeyDown={onKeyDown}
                    className="inline-flex rounded-md border border-border bg-muted p-0.5"
                >
                    {bar.options.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            role="radio"
                            aria-checked={option.active}
                            tabIndex={option.active ? 0 : -1}
                            disabled={!option.available || pending !== null}
                            title={option.description}
                            data-lens={option.value}
                            onClick={() => choose(option.value)}
                            className={
                                'rounded px-3 py-1 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50 ' +
                                (option.active
                                    ? 'bg-background font-medium text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground')
                            }
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
                {bar.scenarios && bar.scenarios.length > 0 && (
                    <label className="inline-flex items-center gap-2 text-xs text-muted-foreground">
                        <span>Scenario</span>
                        <select
                            data-exec-lens-scenario
                            value={bar.scenarios.find((option) => option.active)?.value ?? ''}
                            disabled={!bar.switchUrl || pending !== null}
                            onChange={(event) => event.target.value !== '' && choose(event.target.value)}
                            className="rounded-md border border-border bg-background px-2 py-1 text-sm text-foreground"
                        >
                            <option value="">Choose a scenario…</option>
                            {bar.scenarios.map((option) => (
                                <option key={option.value} value={option.value} title={option.description}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                {!bar.chosen && <span className="text-xs text-muted-foreground">Default lens</span>}
                {failed && <span className="text-xs text-destructive">{failed}</span>}
            </div>
            {bar.banner && <LensBanner banner={bar.banner} />}
        </div>
    );
}

/** The undismissable banner. No close control, by design (owner ruling D12: mandatory and not configurable). */
export function LensBanner({ banner }: { banner: BoardLensBannerData }) {
    return (
        <div
            role="status"
            data-exec-lens-banner={banner.kind}
            className="flex flex-wrap items-baseline gap-x-2 border-y border-warning border-l-4 bg-warning/15 px-6 py-2 text-sm text-foreground"
        >
            <strong className="font-semibold">{banner.label}</strong>
            <span>{banner.detail}</span>
        </div>
    );
}

function reloadPage(): void {
    window.location.reload();
}

/** The default client: same-origin fetch, JSON, with Laravel's XSRF cookie echoed on the write. */
export const fetchClient: BoardLensClient = {
    read: (url) => getJson<BoardLensBarData>(url),
    choose: (url, lens) => postJson<BoardLensBarData>(url, { lens }),
};
