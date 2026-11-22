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
 * A card instead carries `#[WidgetIn('summary', …)]` on its own class, and this context contributor (keyed by
 * resource key for exactly this) projects it into the root node of that card's `exec-{key}` resource with frame's own
 * projector, so frame validates it as it would on a record.
 *
 * frame-foundation 93: it is registered under the key `beam-exec.board-cards` in frame's `frame.context-contributors`
 * registry, after beam's two, and answers only its own root node. The card's declaration wins a context it shares with
 * beam's nodes because a later position wins in the registry's fold (it no longer decorates anything). Every other key
 * answers nothing.
 */
final class BoardCardContextNodes implements ResourceContextContributor
{
    private readonly WidgetContextProjector $projector;

    /** frame-foundation 89: frame's projector is handed in (beam-exec's scoped binding passes the container's); null = WidgetContextProjector::resolved(). */
    public function __construct(
        private readonly BoardCardRegistry $cards,
        ?WidgetContextProjector $projector = null,
    ) {
        $this->projector = $projector ?? WidgetContextProjector::resolved();
    }

    public function nodesFor(string $key): array
    {
        $definition = $this->cards->forResource($key);

        if ($definition === null) {
            return [];
        }

        $root = $this->projector->forClass(new ReflectionClass($definition->class));

        return $root === [] ? [] : ['' => $root];
    }
}
