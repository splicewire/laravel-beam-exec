import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@schemastud/ui';
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
 * The board's lens bar: a segmented switch over Live, Truth, Scenario and Projection, and the kit's select for the
 * scenario to read.
 *
 * The bar draws no band. The server still sends `banner`, and the bar leaves it unread: what marks synthesized or
 * projected figures on the page is the checked lens, the scenario's name in the picker, and the watermark row the server
 * stamps on each card (owner, 2026-11-12, superseding ADR-0004 clause 5 for the band). `LensBanner` stays exported for a
 * host that wants to draw one.
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
                    <div className="inline-flex items-center gap-2 text-xs text-muted-foreground">
                        <span id="exec-lens-scenario-label">Scenario</span>
                        <Select
                            // '' shows the placeholder: Radix's way to say no scenario is being read.
                            value={bar.scenarios.find((option) => option.active)?.value ?? ''}
                            disabled={!bar.switchUrl || pending !== null}
                            onValueChange={choose}
                        >
                            <SelectTrigger data-exec-lens-scenario aria-labelledby="exec-lens-scenario-label" className="h-8 w-auto min-w-48">
                                <SelectValue placeholder="Choose a scenario…" />
                            </SelectTrigger>
                            <SelectContent>
                                {bar.scenarios.map((option) => (
                                    <SelectItem key={option.value} value={option.value} title={option.description}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
                {!bar.chosen && <span className="text-xs text-muted-foreground">Default lens</span>}
                {failed && <span className="text-xs text-destructive">{failed}</span>}
            </div>
        </div>
    );
}

/**
 * A band naming a synthesized or projected board. The lens bar no longer draws it (owner, 2026-11-12); it stays
 * exported, unused by the bar, for a host that wants one. It has no close control.
 */
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
