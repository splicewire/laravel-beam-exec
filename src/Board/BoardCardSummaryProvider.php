<?php

namespace Splicewire\Beam\Exec\Board;

use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/**
 * The summary provider for every `exec-{card}` resource: the card's figures as its dashboard card.
 *
 * A summary provider is handed only the resource definition, so the card is found by the resource's key, and the
 * figures are measured through the container's {@see BoardSource}, the same reading the card's own page lists.
 *
 * It declines (null) for a key that names no card, and for a card that measures no figure: an empty card is an
 * honest absence, and the dashboard drops the row rather than drawing a blank tile.
 */
final class BoardCardSummaryProvider implements ResourceSummaryProvider
{
    public function __construct(
        private readonly BoardCardRegistry $cards,
        private readonly BoardSource $source,
    ) {}

    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        $definition = $this->cards->forResource($resource->key);

        if ($definition === null) {
            return null;
        }

        $figures = $this->cards->figures($definition, $this->source);

        if ($figures === []) {
            return null;
        }

        return new SummaryResponseData(
            key: $resource->key,
            label: $resource->resolvedLabel(),
            icon: $resource->nav->icon,
            figures: array_values(array_map(fn (BoardFigureData $figure) => $figure->toSummaryFigure(), $figures)),
            overview: null,
        );
    }
}
