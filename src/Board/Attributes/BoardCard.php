<?php

namespace Splicewire\Beam\Exec\Board\Attributes;

use Attribute;
use Splicewire\Beam\Exec\Board\BoardMetric;

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
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $icon = null,
        public ?int $order = null,
        public ?string $ability = null,
    ) {}
}
