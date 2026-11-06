import { useCallback, useEffect, useId, useMemo, useRef, useState, type ComponentType } from 'react';
import { getJson, message, postJson, RequestError } from './http';
import type {
    BoardLensBarData,
    PortfolioMemberData,
    ScenarioCatalogData,
    ScenarioDraftData,
    ScenarioEntryData,
    ScenarioFigure,
    ScenarioPreviewData,
} from './types';

/** How the panel talks to the server. The default is same-origin fetch over the declared scenario operations. */
export interface ScenarioClient {
    catalog(url: string): Promise<ScenarioCatalogData>;
    preview(url: string, draft: ScenarioDraftData): Promise<ScenarioPreviewData>;
    save(url: string, scenario: ScenarioDraftData & { id: string }): Promise<ScenarioCatalogData>;
    remove(url: string, id: string): Promise<ScenarioCatalogData>;
    show(url: string, lens: string): Promise<BoardLensBarData>;
}

export const scenarioFetchClient: ScenarioClient = {
    catalog: (url) => getJson<ScenarioCatalogData>(url),
    preview: (url, draft) => postJson<ScenarioPreviewData>(url, draft),
    save: (url, scenario) => postJson<ScenarioCatalogData>(url, scenario),
    remove: (url, id) => postJson<ScenarioCatalogData>(url, { id }),
    show: (url, lens) => postJson<BoardLensBarData>(url, { lens }),
};

/**
 * The trend widget the preview draws its MRR series with: `@schemastud/frame`'s `TrendCard` fits (`value` is a summary
 * payload). The host passes it in, so this package needs no frame dependency; without one the series is a table.
 */
export type TrendWidget = ComponentType<{ value: { key: string; label: string; figures: ScenarioFigure[] } }>;

export interface ScenarioPanelProps {
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

const WATERMARK = new Set(['synthesized', 'projected']);
const HEADLINE = ['mrr', 'arr', 'paying', 'net-new-mrr'];

function emptyDraft(catalog: ScenarioCatalogData | null): ScenarioDraftData {
    const first = catalog?.blueprints[0]?.id ?? '';

    return { label: 'New scenario', members: first ? [{ blueprint: first, count: 1, startOffsetMonths: 0 }] : [], churnRate: null, expansionRate: null };
}

function fromEntry(entry: ScenarioEntryData): ScenarioDraftData {
    return {
        label: entry.label,
        members: entry.members.map((member) => ({ ...member })),
        churnRate: entry.churnRate,
        expansionRate: entry.expansionRate,
    };
}

function kebab(label: string): string {
    return label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 100);
}

/**
 * The board's Scenarios panel: every scenario the board can show (the host's portfolio files, then the scenarios
 * stored with the builder), a composer that previews a draft live, and "Save as scenario", after which the scenario is
 * a lens (`scenario:<id>`) the bar can choose at once.
 *
 * Everything it draws comes from the declared operations; nothing is computed here. A preview's figures carry the
 * synthesized watermark the server stamps, and the composer says so.
 */
export function ScenarioPanel({
    url = '/exec/scenarios',
    client = scenarioFetchClient,
    trendCard: Trend,
    onChanged,
    onShown = reloadPage,
    defaultOpen = false,
    previewDelay = 400,
}: ScenarioPanelProps) {
    const ids = useId();
    const [open, setOpen] = useState(defaultOpen);
    const [catalog, setCatalog] = useState<ScenarioCatalogData | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [draft, setDraft] = useState<ScenarioDraftData | null>(null);
    const [preview, setPreview] = useState<ScenarioPreviewData | null>(null);
    const [previewError, setPreviewError] = useState<RequestError | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [saveId, setSaveId] = useState('');
    const [saveError, setSaveError] = useState<RequestError | null>(null);
    const [notice, setNotice] = useState<{ text: string; lens?: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const sequence = useRef(0);

    useEffect(() => {
        if (!open || catalog) {
            return;
        }

        let live = true;
        client.catalog(url).then(
            (read) => {
                if (!live) return;
                setCatalog(read);
                setDraft((current) => current ?? emptyDraft(read));
            },
            (error: unknown) => live && setFailed(message(error)),
        );

        return () => {
            live = false;
        };
    }, [catalog, client, open, url]);

    // The live preview: every edit re-runs it after a short pause; only the latest answer is drawn.
    useEffect(() => {
        if (!draft || !catalog?.previewUrl || draft.members.length === 0) {
            return;
        }

        const ticket = ++sequence.current;
        const timer = setTimeout(() => {
            setPreviewing(true);
            client.preview(catalog.previewUrl as string, draft).then(
                (answer) => {
                    if (ticket !== sequence.current) return;
                    setPreview(answer);
                    setPreviewError(null);
                    setPreviewing(false);
                },
                (error: unknown) => {
                    if (ticket !== sequence.current) return;
                    setPreviewError(error instanceof RequestError ? error : new RequestError(message(error), 0));
                    setPreviewing(false);
                },
            );
        }, previewDelay);

        return () => clearTimeout(timer);
    }, [catalog?.previewUrl, client, draft, previewDelay]);

    const blueprintName = useMemo(() => new Map((catalog?.blueprints ?? []).map((b) => [b.id, b.name])), [catalog]);

    const update = useCallback((patch: Partial<ScenarioDraftData>) => setDraft((current) => (current ? { ...current, ...patch } : current)), []);
    const updateMember = (index: number, patch: Partial<PortfolioMemberData>) =>
        setDraft((current) =>
            current ? { ...current, members: current.members.map((member, i) => (i === index ? { ...member, ...patch } : member)) } : current,
        );

    const changed = (next: ScenarioCatalogData, text: string, lens?: string) => {
        setCatalog(next);
        setNotice({ text, lens });
        onChanged?.();
    };

    const save = () => {
        if (!draft || !catalog?.saveUrl || busy) return;
        const id = saveId.trim() || kebab(draft.label);
        setBusy(true);
        setSaveError(null);
        client.save(catalog.saveUrl, { ...draft, id }).then(
            (next) => {
                setBusy(false);
                setSaveId(id);
                changed(next, `Saved “${draft.label}” as scenario:${id}.`, `scenario:${id}`);
            },
            (error: unknown) => {
                setBusy(false);
                setSaveError(error instanceof RequestError ? error : new RequestError(message(error), 0));
            },
        );
    };

    const remove = (entry: ScenarioEntryData) => {
        if (!catalog?.deleteUrl || busy) return;
        setBusy(true);
        client.remove(catalog.deleteUrl, entry.id).then(
            (next) => {
                setBusy(false);
                changed(next, `Deleted “${entry.label}”.`);
            },
            (error: unknown) => {
                setBusy(false);
                setNotice({ text: message(error) });
            },
        );
    };

    const show = (lens: string) => {
        if (!catalog?.switchUrl || busy) return;
        setBusy(true);
        client.show(catalog.switchUrl, lens).then(
            (bar) => {
                setBusy(false);
                onShown(bar);
            },
            (error: unknown) => {
                setBusy(false);
                setNotice({ text: message(error) });
            },
        );
    };

    const fieldError = (error: RequestError | null, prefix: string): string | undefined =>
        error ? Object.entries(error.errors).find(([key]) => key === prefix || key.startsWith(`${prefix}.`))?.[1]?.[0] : undefined;

    const panelId = `${ids}-panel`;
    const canSave = Boolean(catalog?.canManage && catalog.storeAvailable && catalog.saveUrl);
    const headline = (preview?.revenue ?? []).filter((figure) => HEADLINE.includes(figure.key));
    const series = (preview?.trend ?? []).filter((figure) => !WATERMARK.has(figure.key));
    const watermark = preview?.revenue.find((figure) => WATERMARK.has(figure.key));

    return (
        <section data-exec-scenarios={open ? 'open' : 'closed'} className="border-b border-border bg-background">
            <div className="flex items-center gap-3 px-6 py-2">
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={panelId}
                    onClick={() => setOpen((value) => !value)}
                    className="rounded-md border border-border px-3 py-1 text-sm text-foreground hover:bg-muted"
                >
                    Scenarios
                </button>
                <span className="text-xs text-muted-foreground">Compose a synthetic portfolio, preview it, and save it as a lens.</span>
            </div>

            {open && (
                <div id={panelId} className="space-y-6 px-6 pb-6">
                    {!catalog ? (
                        <p className="text-sm text-muted-foreground">{failed ? `The scenarios did not load: ${failed}` : 'Loading scenarios…'}</p>
                    ) : (
                        <>
                            <div role="status" aria-live="polite" className="text-sm text-foreground" data-exec-scenario-notice>
                                {notice && (
                                    <span className="inline-flex flex-wrap items-center gap-2">
                                        {notice.text}
                                        {notice.lens && catalog.switchUrl && (
                                            <button
                                                type="button"
                                                onClick={() => show(notice.lens as string)}
                                                className="rounded-md border border-border px-2 py-0.5 text-sm hover:bg-muted"
                                            >
                                                Show on board
                                            </button>
                                        )}
                                    </span>
                                )}
                            </div>

                            <section aria-labelledby={`${ids}-list`}>
                                <h2 id={`${ids}-list`} className="mb-2 text-sm font-semibold text-foreground">
                                    Scenarios on this board
                                </h2>
                                <ul className="divide-y divide-border rounded-md border border-border" data-exec-scenario-list>
                                    {catalog.scenarios.map((entry) => (
                                        <li key={entry.id} data-exec-scenario={entry.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
                                            <span className="font-medium text-foreground">{entry.label}</span>
                                            <span className="rounded border border-border px-1.5 text-xs text-muted-foreground">
                                                {entry.source === 'file' ? 'Declared file' : 'Saved'}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {entry.members
                                                    .map((m) => `${m.count} × ${blueprintName.get(m.blueprint) ?? m.blueprint}${m.startOffsetMonths ? ` from month ${m.startOffsetMonths + 1}` : ''}`)
                                                    .join(', ')}
                                            </span>
                                            <span className="ml-auto inline-flex gap-2">
                                                <button
                                                    type="button"
                                                    disabled={entry.active || busy || !catalog.switchUrl}
                                                    onClick={() => show(entry.lens)}
                                                    aria-label={entry.active ? `${entry.label} is on the board` : `Show ${entry.label} on the board`}
                                                    className="rounded-md border border-border px-2 py-0.5 hover:bg-muted disabled:opacity-50"
                                                >
                                                    {entry.active ? 'On the board' : 'Show on board'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setDraft(fromEntry(entry));
                                                        setSaveId(entry.source === 'stored' ? entry.id : '');
                                                    }}
                                                    aria-label={`Compose from ${entry.label}`}
                                                    className="rounded-md border border-border px-2 py-0.5 hover:bg-muted"
                                                >
                                                    Compose from
                                                </button>
                                                {entry.source === 'stored' && catalog.canManage && catalog.deleteUrl && (
                                                    <button
                                                        type="button"
                                                        disabled={busy}
                                                        onClick={() => remove(entry)}
                                                        aria-label={`Delete ${entry.label}`}
                                                        className="rounded-md border border-border px-2 py-0.5 text-destructive hover:bg-muted disabled:opacity-50"
                                                    >
                                                        Delete
                                                    </button>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>

                            {draft && (
                                <form
                                    aria-labelledby={`${ids}-composer`}
                                    data-exec-scenario-composer
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        save();
                                    }}
                                    className="grid gap-6 lg:grid-cols-2"
                                >
                                    <div className="space-y-4">
                                        <h2 id={`${ids}-composer`} className="text-sm font-semibold text-foreground">
                                            Compose a scenario
                                        </h2>
                                        <label className="block text-sm">
                                            <span className="mb-1 block text-muted-foreground">Name</span>
                                            <input
                                                value={draft.label}
                                                onChange={(event) => update({ label: event.target.value })}
                                                aria-invalid={fieldError(previewError, 'label') ? true : undefined}
                                                className="w-full rounded-md border border-border bg-background px-2 py-1 text-foreground"
                                            />
                                        </label>

                                        <fieldset className="space-y-2">
                                            <legend className="mb-1 text-sm text-muted-foreground">Members</legend>
                                            {draft.members.map((member, index) => {
                                                const row = `${ids}-member-${index}`;
                                                const error =
                                                    fieldError(previewError, `members.${index}`) ?? fieldError(saveError, `members.${index}`);

                                                return (
                                                    <div key={index} className="flex flex-wrap items-end gap-2" data-exec-scenario-member={index}>
                                                        <label className="text-sm">
                                                            <span className="mb-1 block text-xs text-muted-foreground">Blueprint</span>
                                                            <select
                                                                value={member.blueprint}
                                                                onChange={(event) => updateMember(index, { blueprint: event.target.value })}
                                                                aria-describedby={error ? `${row}-error` : undefined}
                                                                className="rounded-md border border-border bg-background px-2 py-1 text-foreground"
                                                            >
                                                                {catalog.blueprints.map((blueprint) => (
                                                                    <option key={blueprint.id} value={blueprint.id}>
                                                                        {blueprint.name} ({blueprint.planSlug}, {blueprint.scenario})
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </label>
                                                        <label className="text-sm">
                                                            <span className="mb-1 block text-xs text-muted-foreground">Tenants</span>
                                                            <input
                                                                type="number"
                                                                min={1}
                                                                max={50}
                                                                value={member.count}
                                                                onChange={(event) => updateMember(index, { count: Number(event.target.value) })}
                                                                className="w-20 rounded-md border border-border bg-background px-2 py-1 text-foreground"
                                                            />
                                                        </label>
                                                        <label className="text-sm">
                                                            <span className="mb-1 block text-xs text-muted-foreground">Starts after (months)</span>
                                                            <input
                                                                type="number"
                                                                min={0}
                                                                max={36}
                                                                value={member.startOffsetMonths}
                                                                onChange={(event) => updateMember(index, { startOffsetMonths: Number(event.target.value) })}
                                                                className="w-24 rounded-md border border-border bg-background px-2 py-1 text-foreground"
                                                            />
                                                        </label>
                                                        <button
                                                            type="button"
                                                            disabled={draft.members.length === 1}
                                                            onClick={() => update({ members: draft.members.filter((_, i) => i !== index) })}
                                                            aria-label={`Remove member ${index + 1}`}
                                                            className="rounded-md border border-border px-2 py-1 text-sm hover:bg-muted disabled:opacity-50"
                                                        >
                                                            Remove
                                                        </button>
                                                        {error && (
                                                            <p id={`${row}-error`} className="w-full text-xs text-destructive">
                                                                {error}
                                                            </p>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    update({
                                                        members: [...draft.members, { blueprint: catalog.blueprints[0]?.id ?? '', count: 1, startOffsetMonths: 0 }],
                                                    })
                                                }
                                                className="rounded-md border border-border px-2 py-1 text-sm hover:bg-muted"
                                            >
                                                Add a blueprint
                                            </button>
                                        </fieldset>

                                        <fieldset className="flex flex-wrap gap-4">
                                            <legend className="mb-1 text-sm text-muted-foreground">Monthly rates for a projection (optional)</legend>
                                            {(['churnRate', 'expansionRate'] as const).map((key) => (
                                                <label key={key} className="text-sm">
                                                    <span className="mb-1 block text-xs text-muted-foreground">
                                                        {key === 'churnRate' ? 'Churn (%)' : 'Expansion (%)'}
                                                    </span>
                                                    <input
                                                        type="number"
                                                        min={0}
                                                        max={100}
                                                        step={0.1}
                                                        value={draft[key] === null ? '' : Math.round(draft[key] * 1000) / 10}
                                                        onChange={(event) =>
                                                            update({ [key]: event.target.value === '' ? null : Number(event.target.value) / 100 })
                                                        }
                                                        className="w-24 rounded-md border border-border bg-background px-2 py-1 text-foreground"
                                                    />
                                                </label>
                                            ))}
                                            <p className="w-full text-xs text-muted-foreground">
                                                A scenario replays its blueprints&apos; curves as they are. The rates are kept with it for the
                                                Projection lens, which borrows them.
                                            </p>
                                        </fieldset>

                                        {canSave ? (
                                            <div className="flex flex-wrap items-end gap-2">
                                                <label className="text-sm">
                                                    <span className="mb-1 block text-xs text-muted-foreground">Scenario id</span>
                                                    <input
                                                        value={saveId}
                                                        placeholder={kebab(draft.label)}
                                                        onChange={(event) => setSaveId(event.target.value)}
                                                        aria-invalid={fieldError(saveError, 'id') ? true : undefined}
                                                        aria-describedby={fieldError(saveError, 'id') ? `${ids}-id-error` : undefined}
                                                        className="w-56 rounded-md border border-border bg-background px-2 py-1 font-mono text-foreground"
                                                    />
                                                </label>
                                                <button
                                                    type="submit"
                                                    disabled={busy}
                                                    className="rounded-md bg-primary px-3 py-1 text-sm font-medium text-primary-foreground disabled:opacity-50"
                                                >
                                                    Save as scenario
                                                </button>
                                                {saveError && (
                                                    <p id={`${ids}-id-error`} role="alert" className="w-full text-xs text-destructive">
                                                        {saveError.message}
                                                    </p>
                                                )}
                                            </div>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">
                                                {catalog.storeAvailable
                                                    ? 'You can preview scenarios; saving one is for operators.'
                                                    : 'This host does not store scenarios.'}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-3" aria-busy={previewing} data-exec-scenario-preview>
                                        <h2 className="text-sm font-semibold text-foreground">Preview</h2>
                                        {watermark && (
                                            <p className="border-l-4 border-warning bg-warning/15 px-3 py-1 text-sm text-foreground" data-exec-scenario-watermark>
                                                <strong className="font-semibold">{watermark.label}</strong> · {String(watermark.value)}
                                            </p>
                                        )}
                                        {previewError && !Object.keys(previewError.errors).some((key) => key.startsWith('members.')) && (
                                            <p role="alert" className="text-sm text-destructive">
                                                {previewError.message}
                                            </p>
                                        )}
                                        {preview && (
                                            <>
                                                <p className="text-xs text-muted-foreground">
                                                    {preview.tenants} synthetic tenants over {preview.months} months, ending {preview.asOf}.
                                                </p>
                                                <dl className="grid grid-cols-2 gap-2" data-exec-scenario-figures>
                                                    {headline.map((figure) => (
                                                        <div key={figure.key} className="rounded-md border border-border px-3 py-2">
                                                            <dt className="text-xs text-muted-foreground">{figure.label}</dt>
                                                            <dd className="text-lg font-semibold tabular-nums text-foreground">{String(figure.value)}</dd>
                                                        </div>
                                                    ))}
                                                </dl>
                                                {Trend ? (
                                                    <Trend value={{ key: 'scenario-mrr-trend', label: 'MRR trend', figures: series }} />
                                                ) : (
                                                    <table className="w-full text-xs" data-exec-scenario-trend>
                                                        <caption className="text-left text-muted-foreground">MRR trend</caption>
                                                        <thead>
                                                            <tr>
                                                                <th scope="col" className="text-left font-medium">
                                                                    Month
                                                                </th>
                                                                <th scope="col" className="text-right font-medium">
                                                                    MRR
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {series.map((figure) => (
                                                                <tr key={figure.key}>
                                                                    <th scope="row" className="text-left font-normal">
                                                                        {figure.label}
                                                                    </th>
                                                                    <td className="text-right tabular-nums">{String(figure.value)}</td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                )}
                                            </>
                                        )}
                                    </div>
                                </form>
                            )}
                        </>
                    )}
                </div>
            )}
        </section>
    );
}

function reloadPage(): void {
    window.location.reload();
}
