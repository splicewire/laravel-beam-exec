<?php

namespace Splicewire\Beam\Exec\Board;

use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

/** Optional card contract; ordinary BoardMetric implementations need no facet methods. */
interface FacetedBoardMetric extends BoardMetric
{
    public function filterVocabulary(): FilterVocabulary;

    /** @return list<BoardFigureData> */
    public function measureFiltered(BoardSource $source, array $filters): array;
}
