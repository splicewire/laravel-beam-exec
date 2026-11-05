import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BoardLensBar, execPages, type BoardLensBarData, type BoardLensClient } from '../src/index';

afterEach(cleanup);

const options = (active: string, unavailable: string[] = []) =>
    ['live', 'truth', 'scenario', 'projection'].map((value) => ({
        value,
        label: value[0].toUpperCase() + value.slice(1),
        description: `${value} lens`,
        active: value === active,
        available: !unavailable.includes(value),
    }));

const bar = (mode: BoardLensBarData['mode'], banner: BoardLensBarData['banner'] = null, unavailable: string[] = []): BoardLensBarData => ({
    lens: mode,
    mode,
    chosen: mode !== 'live',
    defaultMode: 'live',
    options: options(mode, unavailable),
    banner,
    switchUrl: '/exec/switch-lens',
});

const synthesized = { kind: 'synthesized' as const, label: 'Synthesized — not real data', detail: 'Demo mixed book' };
const projected = { kind: 'projected' as const, label: 'Projected — not real data', detail: 'Live, October 2026 held flat, 6 months ahead' };

function client(read: BoardLensBarData, chosen?: BoardLensBarData): BoardLensClient & { choose: ReturnType<typeof vi.fn> } {
    return {
        read: vi.fn(async () => read),
        choose: vi.fn(async () => chosen ?? read),
    };
}

describe('BoardLensBar', () => {
    it('draws a four-segment radio group with the active lens checked, and no banner on a live reading', async () => {
        render(<BoardLensBar client={client(bar('live'))} />);

        const group = await screen.findByRole('radiogroup', { name: 'Lens' });
        const radios = screen.getAllByRole('radio');

        expect(group).toBeTruthy();
        expect(radios.map((r) => [r.textContent, r.getAttribute('aria-checked')])).toEqual([
            ['Live', 'true'],
            ['Truth', 'false'],
            ['Scenario', 'false'],
            ['Projection', 'false'],
        ]);
        expect(screen.queryByRole('status')).toBeNull();
        expect(screen.getByText('Default lens')).toBeTruthy();
    });

    it.each([
        ['scenario', synthesized],
        ['projection', projected],
    ] as const)('shows an undismissable banner under %s', async (mode, banner) => {
        const { container } = render(<BoardLensBar client={client(bar(mode, banner))} />);

        const status = await screen.findByRole('status');

        expect(status.getAttribute('data-exec-lens-banner')).toBe(banner.kind);
        expect(status.textContent).toContain(banner.label);
        expect(status.textContent).toContain(banner.detail);
        // No close control of any kind: the banner holds no button, and the bar's only buttons are the four lenses.
        expect(status.querySelectorAll('button, [role="button"], [aria-label*="lose" i], [aria-label*="ismiss" i]')).toHaveLength(0);
        expect(Array.from(container.querySelectorAll('button')).map((b) => b.getAttribute('role'))).toEqual(['radio', 'radio', 'radio', 'radio']);
    });

    it('posts the chosen lens to the switch, then hands the answer on', async () => {
        const api = client(bar('live'), bar('scenario', synthesized));
        const onSwitched = vi.fn();
        render(<BoardLensBar client={api} onSwitched={onSwitched} url="/exec/lens" />);

        fireEvent.click(await screen.findByRole('radio', { name: 'Scenario' }));

        await waitFor(() => expect(onSwitched).toHaveBeenCalledTimes(1));
        expect(api.read).toHaveBeenCalledWith('/exec/lens');
        expect(api.choose).toHaveBeenCalledWith('/exec/switch-lens', 'scenario');
        expect(screen.getByRole('status').textContent).toContain('Synthesized — not real data');
    });

    it('does not post the lens already active, nor a lens the host cannot honour', async () => {
        const api = client(bar('live', null, ['scenario']));
        render(<BoardLensBar client={api} onSwitched={vi.fn()} />);

        fireEvent.click(await screen.findByRole('radio', { name: 'Live' }));
        const scenario = screen.getByRole('radio', { name: 'Scenario' }) as HTMLButtonElement;
        fireEvent.click(scenario);

        expect(scenario.disabled).toBe(true);
        expect(api.choose).not.toHaveBeenCalled();
    });

    it('moves between lenses with the arrow keys', async () => {
        const api = client(bar('live'), bar('truth'));
        render(<BoardLensBar client={api} onSwitched={vi.fn()} />);

        fireEvent.keyDown(await screen.findByRole('radiogroup'), { key: 'ArrowRight' });

        await waitFor(() => expect(api.choose).toHaveBeenCalledWith('/exec/switch-lens', 'truth'));
    });

    it('says so when the bar does not load', async () => {
        render(<BoardLensBar client={{ read: async () => Promise.reject(new Error('403 from /exec/lens')), choose: vi.fn() }} />);

        expect(await screen.findByText(/did not load: 403 from \/exec\/lens/)).toBeTruthy();
    });
});

describe('execPages', () => {
    it('serves exec/console: the lens bar, read from the realm base, above the resolved frame console', async () => {
        const resolve = vi.fn(async () => (props: { basename?: string }) => <p data-testid="frame-console">{props.basename}</p>);
        const api = client(bar('live'));
        globalThis.fetch = vi.fn(async () =>
            new Response(JSON.stringify({ data: bar('live') }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        ) as typeof fetch;

        const pages = execPages(resolve);
        const first = await pages['exec/console']();
        const second = await pages['exec/console']();
        const Page = first.default;

        await act(async () => {
            render(<Page realm="exec" basename="/exec" manifestUrl="/exec/frame/manifest" />);
        });

        expect(second.default).toBe(first.default);
        expect(await screen.findByTestId('frame-console')).toBeTruthy();
        expect(resolve).toHaveBeenCalledWith('frame/console');
        expect(await screen.findByRole('radiogroup')).toBeTruthy();
        expect(globalThis.fetch).toHaveBeenCalledWith('/exec/lens', expect.anything());
        expect(api.read).not.toHaveBeenCalled();
    });
});
