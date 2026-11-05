<?php

namespace Splicewire\Beam\Exec\Board\Attributes;

use Attribute;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Board\BoardSource;

/**
 * Declares a {@see BoardMetric} as one card on the board: the `exec` realm's dashboard, and a leaf in its `board`
 * section.
 *
 * Each declared card becomes its own read-only particle resource, `exec-{key}`, registered in the `exec` realm. Its
 * rows are the card's figures, and its summary provider draws the same figures as the dashboard card. Declaring the
 * attribute is not enough on its own: the class must also be listed in `beam.exec.cards` (config, or
 * `BoardCardRegistry::add()`), the way every other registry entry is.
 *
 * ```php
 * #[BoardCard(key: 'tenants', label: 'Tenants', icon: 'Building2', order: 10)]
 * final class TenantsCard implements BoardMetric { … }
 * ```
 *
 * A card that reads only revenue may declare `projects: true`: it is then measured under the Projection lens too,
 * where every other card is absent, because a projection models revenue and nothing else. A card that projects
 * marks each figure that draws a projected month with `Figures::forecast()`.
 *
 * A card may also carry frame's class-level `#[WidgetIn('summary', …)]` to choose its dashboard widget, such as
 * `trend-card` for a card whose figures are one period each. The board contributes it to the card's resource, so
 * one card can choose a widget without changing the figure shape every card shares.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BoardCard
{
    /**
     * @param  string  $key  the card's key, kebab-case. Its resource is `exec-{key}`, mounted at `/exec/exec-{key}`.
     * @param  string  $label  the card's title, on the dashboard and in the board section
     * @param  string|null  $icon  a Lucide icon name for the card and its nav leaf
     * @param  int|null  $order  the card's place among the board's cards, ascending; null sorts after every ordered card
     * @param  string|null  $ability  a Gate ability narrower than the realm's (`entitlement:exec.view`); null reads the realm gate
     * @param  bool  $projects  whether the card is measured under the Projection lens: true only for a card that reads
     *                          nothing but {@see BoardSource::revenue()}
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $icon = null,
        public ?int $order = null,
        public ?string $ability = null,
        public bool $projects = false,
    ) {}
}
