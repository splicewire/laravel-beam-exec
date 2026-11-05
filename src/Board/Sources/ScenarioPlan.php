<?php

namespace Splicewire\Beam\Exec\Board\Sources;

use Splicewire\Beam\Commerce\Billing\PlanPricer;

/**
 * One plan on a scenario's price list: its slug, its display name and its billing components, copied from the
 * host's plan catalog when the scenario is built. A plain value, so pricing a scenario month reads no row; the
 * components are the same list {@see PlanPricer} composes a live bill from.
 */
final class ScenarioPlan
{
    /**
     * @param  array<int, array{type: string, config?: array<string, mixed>}>  $components
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly array $components,
    ) {}
}
