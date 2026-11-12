import { fireEvent, screen, within } from '@testing-library/react';

// jsdom has no layout or pointer capture; Radix Select calls both while it opens and scrolls to the checked item.
Element.prototype.scrollIntoView ??= function scrollIntoView() {};
Element.prototype.hasPointerCapture ??= () => false;
Element.prototype.releasePointerCapture ??= () => {};

/** Opens a kit select from its trigger and returns the labels it lists, in order. */
export async function optionsOf(trigger: HTMLElement): Promise<string[]> {
    fireEvent.keyDown(trigger, { key: 'Enter' });
    const listbox = await screen.findByRole('listbox');
    const labels = within(listbox).getAllByRole('option').map((option) => option.textContent ?? '');
    fireEvent.keyDown(listbox, { key: 'Escape' });

    return labels;
}

/** Opens a kit select from its trigger and chooses the option with this label, as a keyboard user would. */
export async function choose(trigger: HTMLElement, label: string): Promise<void> {
    fireEvent.keyDown(trigger, { key: 'Enter' });
    fireEvent.keyDown(await screen.findByRole('option', { name: label }), { key: 'Enter' });
}
