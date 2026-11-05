<?php

namespace Splicewire\Beam\Exec\Board;

use ReflectionClass;
use Schemastud\Frame\Contracts\ResourceContextContributor;
use Schemastud\Frame\Registry\WidgetContextProjector;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/**
 * A card's own widget choice, contributed to its resource's render contexts.
 *
 * Every card resource reads the same record class, {@see BoardFigureData}, and frame reflects a resource's
 * class-level `#[WidgetIn]` off that class, so a declaration there would choose the widget for every card at once.
 * A card instead carries `#[WidgetIn('summary', …)]` on its own class, and this plug, on frame's
 * {@see ResourceContextContributor} port (which is keyed by resource key for exactly this), projects it into the
 * root node of that card's `exec-{key}` resource with frame's own projector, so frame validates it as it would on a
 * record.
 *
 * It decorates whatever is bound to the port (beam's contribution and collection nodes), and the card's declaration
 * wins a context both name: it is the resource's own. Every other key passes through unchanged.
 */
final class BoardCardContextNodes implements ResourceContextContributor
{
    public function __construct(
        private readonly ResourceContextContributor $inner,
        private readonly BoardCardRegistry $cards,
    ) {}

    public function nodesFor(string $key): array
    {
        $nodes = $this->inner->nodesFor($key);
        $definition = $this->cards->forResource($key);

        if ($definition === null) {
            return $nodes;
        }

        $root = (new WidgetContextProjector)->forClass(new ReflectionClass($definition->class));

        if ($root !== []) {
            $nodes[''] = array_merge($nodes[''] ?? [], $root);
        }

        return $nodes;
    }
}
