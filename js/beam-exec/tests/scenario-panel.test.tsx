import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    BoardLensBar,
    RequestError,
    ScenarioPanel,
    type BoardLensBarData,
    type BoardLensClient,
    type ScenarioCatalogData,
    type ScenarioClient,
    type ScenarioPreviewData,
} from '../src/index';
import { choose, optionsOf } from './select';

afterEach(cleanup);

const blueprints = [
    { id: 'demo-orrowby-hart', name: 'Orrowby & Hart', planSlug: 'professional', scenario: 'dip-then-recover', windowMonths: 12 },
    { id: 'demo-quillmere-studio', name: 'Quillmere Studio', planSlug: 'professional', scenario: 'steady-ramp', windowMonths: 12 },
];

const fileEntry = {
    id: 'demo-mixed-book',
    label: 'Demo mixed book',
    lens: 'scenario:demo-mixed-book',
    source: 'file' as const,
    members: [{ blueprint: 'demo-quillmere-studio', count: 3, startOffsetMonths: 0 }],
    churnRate: 0.02,
    expansionRate: 0.01,
    active: false,
};

const storedEntry = { ...fileEntry, id: 'board-upside', label: 'Board upside', lens: 'scenario:board-upside', source: 'stored' as const };

const catalog = (overrides: Partial<ScenarioCatalogData> = {}): ScenarioCatalogData => ({
    scenarios: [fileEntry],
    blueprints,
    canManage: true,
    storeAvailable: true,
    previewUrl: '/exec/preview-scenario',
    saveUrl: '/exec/save-scenario',
    deleteUrl: '/exec/delete-scenario',
    switchUrl: '/exec/switch-lens',
    ...overrides,
});

const preview: ScenarioPreviewData = {
    label: 'New scenario',
    asOf: '2026-11',
    months: 12,
    tenants: 1,
    revenue: [
        { key: 'synthesized', label: 'Synthesized — not real data', value: 'New scenario', tone: 'warn' },
        { key: 'mrr', label: 'MRR', value: '$407.00', tone: 'active' },
        { key: 'arr', label: 'ARR', value: '$4,884.00' },
        { key: 'new-mrr', label: 'New MRR', value: '$0.00' },
    ],
    trend: [
        { key: 'synthesized', label: 'Synthesized — not real data', value: 'New scenario', tone: 'warn' },
        { key: '2026-10', label: 'Oct 2026', value: 380 },
        { key: '2026-11', label: 'Nov 2026', value: 407 },
    ],
};

const bar = (scenarios: BoardLensBarData['scenarios'] = []): BoardLensBarData => ({
    lens: 'live',
    mode: 'live',
    chosen: false,
    defaultMode: 'live',
    options: ['live', 'truth', 'scenario', 'projection'].map((value) => ({ value, label: value, description: value, active: value === 'live', available: true })),
    banner: null,
    switchUrl: '/exec/switch-lens',
    scenarios,
});

function client(read: ScenarioCatalogData = catalog(), overrides: Partial<ScenarioClient> = {}) {
    const mocks = {
        catalog: vi.fn<ScenarioClient['catalog']>(async () => read),
        preview: vi.fn<ScenarioClient['preview']>(async () => preview),
        save: vi.fn<ScenarioClient['save']>(async () => catalog({ scenarios: [fileEntry, storedEntry] })),
        remove: vi.fn<ScenarioClient['remove']>(async () => catalog()),
        show: vi.fn<ScenarioClient['show']>(async () => bar()),
    };
    for (const [key, fn] of Object.entries(overrides)) {
        mocks[key as keyof ScenarioClient].mockImplementation(fn as never);
    }

    return mocks;
}

async function openPanel(api: ScenarioClient, props: Partial<Parameters<typeof ScenarioPanel>[0]> = {}) {
    render(<ScenarioPanel client={api} previewDelay={0} {...props} />);
    const toggle = screen.getByRole('button', { name: 'Scenarios' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    await screen.findByText('Scenarios on this board');
}

describe('ScenarioPanel', () => {
    it('stays closed until asked, then lists the board\'s scenarios with where each comes from', async () => {
        const api = client(catalog({ scenarios: [fileEntry, storedEntry] }));
        render(<ScenarioPanel client={api} previewDelay={0} />);
        expect(api.catalog).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Scenarios' }));
        const list = await screen.findByRole('list');

        expect(within(list).getAllByRole('listitem').map((item) => item.getAttribute('data-exec-scenario'))).toEqual(['demo-mixed-book', 'board-upside']);
        expect(within(list).getByText('Declared file')).toBeTruthy();
        expect(within(list).getByText('Saved')).toBeTruthy();
        // Only a stored scenario can be deleted.
        expect(screen.queryByRole('button', { name: 'Delete Demo mixed book' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Delete Board upside' })).toBeTruthy();
    });

    it('previews the draft as it is composed, with the watermark and the trend handed to the trend widget', async () => {
        const api = client();
        const Trend = vi.fn(({ value }: { value: { figures: { key: string }[] } }) => <div data-testid="trend">{value.figures.map((f) => f.key).join(',')}</div>);
        await openPanel(api, { trendCard: Trend });

        await waitFor(() => expect(api.preview).toHaveBeenCalledTimes(1));
        expect(api.preview.mock.calls[0]).toEqual([
            '/exec/preview-scenario',
            { label: 'New scenario', members: [{ blueprint: 'demo-orrowby-hart', count: 1, startOffsetMonths: 0 }], churnRate: null, expansionRate: null },
        ]);

        expect((await screen.findByText('$407.00')).textContent).toBe('$407.00');
        expect(screen.getByText('Synthesized — not real data')).toBeTruthy();
        // The series reaches the widget without the watermark figure (the panel states the watermark itself).
        expect(screen.getByTestId('trend').textContent).toBe('2026-10,2026-11');

        fireEvent.change(screen.getByLabelText('Tenants'), { target: { value: '4' } });
        await waitFor(() => expect(api.preview).toHaveBeenCalledTimes(2));
        expect(api.preview.mock.calls[1][1].members[0].count).toBe(4);
    });

    it('composes members from the registry\'s blueprints and sends rates as fractions', async () => {
        const api = client();
        await openPanel(api);
        await waitFor(() => expect(api.preview).toHaveBeenCalled());

        fireEvent.click(screen.getByRole('button', { name: 'Add a blueprint' }));
        const selects = screen.getAllByRole('combobox', { name: 'Blueprint' });
        expect(await optionsOf(selects[1])).toEqual([
            'Orrowby & Hart (professional, dip-then-recover)',
            'Quillmere Studio (professional, steady-ramp)',
        ]);
        await choose(selects[1], 'Quillmere Studio (professional, steady-ramp)');
        fireEvent.change(screen.getAllByLabelText('Starts after (months)')[1], { target: { value: '3' } });
        fireEvent.change(screen.getByLabelText('Churn (%)'), { target: { value: '2.5' } });

        await waitFor(() => {
            const last = api.preview.mock.calls.at(-1)?.[1];
            expect(last?.members).toEqual([
                { blueprint: 'demo-orrowby-hart', count: 1, startOffsetMonths: 0 },
                { blueprint: 'demo-quillmere-studio', count: 1, startOffsetMonths: 3 },
            ]);
            expect(last?.churnRate).toBe(0.025);
        });
    });

    it('saves the draft as a scenario, tells the bar to read again, and offers to show it on the board', async () => {
        const api = client();
        const onChanged = vi.fn();
        const onShown = vi.fn();
        await openPanel(api, { onChanged, onShown });

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Board upside' } });
        expect((screen.getByLabelText('Scenario id') as HTMLInputElement).placeholder).toBe('board-upside');
        fireEvent.click(screen.getByRole('button', { name: 'Save as scenario' }));

        await waitFor(() => expect(api.save).toHaveBeenCalledTimes(1));
        expect(api.save.mock.calls[0][0]).toBe('/exec/save-scenario');
        expect(api.save.mock.calls[0][1]).toMatchObject({ id: 'board-upside', label: 'Board upside' });
        expect(onChanged).toHaveBeenCalledTimes(1);
        expect((await screen.findByRole('status')).textContent).toContain('scenario:board-upside');

        const notice = screen.getByRole('status');
        await act(async () => fireEvent.click(within(notice).getByRole('button', { name: 'Show on board' })));
        expect(api.show).toHaveBeenCalledWith('/exec/switch-lens', 'scenario:board-upside');
        expect(onShown).toHaveBeenCalledTimes(1);
    });

    it('shows a refused save as an alert, and stores nothing in the list', async () => {
        const api = client(catalog(), {
            save: vi.fn(async () => {
                throw new RequestError('That id belongs to a portfolio the host declares in a file.', 422, { id: ['That id belongs to a portfolio the host declares in a file.'] });
            }),
        });
        const onChanged = vi.fn();
        await openPanel(api, { onChanged });

        fireEvent.change(screen.getByLabelText('Scenario id'), { target: { value: 'demo-mixed-book' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save as scenario' }));

        expect((await screen.findByRole('alert')).textContent).toContain('declares in a file');
        expect(screen.getByLabelText('Scenario id').getAttribute('aria-invalid')).toBe('true');
        expect(onChanged).not.toHaveBeenCalled();
    });

    it('lets a board seat preview but offers no save or delete', async () => {
        const api = client(catalog({ canManage: false, scenarios: [fileEntry, storedEntry] }));
        await openPanel(api);
        await waitFor(() => expect(api.preview).toHaveBeenCalled());

        expect(screen.queryByRole('button', { name: 'Save as scenario' })).toBeNull();
        expect(screen.queryByRole('button', { name: /^Delete/ })).toBeNull();
        expect(screen.getByText('You can preview scenarios; saving one is for operators.')).toBeTruthy();
    });

    it('deletes a stored scenario and composes from an existing one', async () => {
        const api = client(catalog({ scenarios: [fileEntry, storedEntry] }));
        const onChanged = vi.fn();
        await openPanel(api, { onChanged });

        fireEvent.click(screen.getByRole('button', { name: 'Compose from Board upside' }));
        expect((screen.getByLabelText('Name') as HTMLInputElement).value).toBe('Board upside');
        expect((screen.getByLabelText('Scenario id') as HTMLInputElement).value).toBe('board-upside');

        fireEvent.click(screen.getByRole('button', { name: 'Delete Board upside' }));
        await waitFor(() => expect(api.remove).toHaveBeenCalledWith('/exec/delete-scenario', 'board-upside'));
        expect(onChanged).toHaveBeenCalledTimes(1);
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Delete Board upside' })).toBeNull());
    });
});

describe('BoardLensBar scenario list', () => {
    it('lists every scenario the board can read and chooses one by its lens value', async () => {
        const scenarios = [
            { value: 'scenario:demo-mixed-book', label: 'Demo mixed book', description: 'Declared by the host.', active: false, available: true },
            { value: 'scenario:board-upside', label: 'Board upside', description: 'Saved with the scenario builder.', active: false, available: true },
        ];
        const api: BoardLensClient & { choose: ReturnType<typeof vi.fn> } = { read: vi.fn(async () => bar(scenarios)), choose: vi.fn(async () => bar(scenarios)) };
        const onSwitched = vi.fn();
        render(<BoardLensBar client={api} onSwitched={onSwitched} />);

        const select = await screen.findByRole('combobox', { name: 'Scenario' });
        expect(select.hasAttribute('data-exec-lens-scenario')).toBe(true);
        expect(select.textContent).toBe('Choose a scenario…');
        expect(await optionsOf(select)).toEqual(['Demo mixed book', 'Board upside']);

        await choose(select, 'Board upside');
        await waitFor(() => expect(api.choose).toHaveBeenCalledWith('/exec/switch-lens', 'scenario:board-upside'));
        expect(onSwitched).toHaveBeenCalledTimes(1);
    });

    it('names the scenario being read in the picker, the mark the band no longer carries', async () => {
        const scenarios = [
            { value: 'scenario:demo-mixed-book', label: 'Demo mixed book', description: 'Declared by the host.', active: true, available: true },
            { value: 'scenario:board-upside', label: 'Board upside', description: 'Saved with the scenario builder.', active: false, available: true },
        ];
        render(<BoardLensBar client={{ read: vi.fn(async () => bar(scenarios)), choose: vi.fn() }} />);

        expect((await screen.findByRole('combobox', { name: 'Scenario' })).textContent).toBe('Demo mixed book');
    });

    it('reads the bar again when its version changes, so a saved scenario is listed at once', async () => {
        const api = { read: vi.fn(async () => bar()), choose: vi.fn(async () => bar()) };
        const { rerender } = render(<BoardLensBar client={api} version={0} />);
        await waitFor(() => expect(api.read).toHaveBeenCalledTimes(1));

        rerender(<BoardLensBar client={api} version={1} />);
        await waitFor(() => expect(api.read).toHaveBeenCalledTimes(2));
    });
});
