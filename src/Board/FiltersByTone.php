<?php

namespace Splicewire\Beam\Exec\Board;

use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

trait FiltersByTone
{
    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::exact('tone', options: 'beam_exec_tones')->titled('Tone'));
    }

    public function measureFiltered(BoardSource $source, array $filters): array
    {
        $rows = $this->measure($source);
        $tone = $filters['tone'] ?? null;
        if (! is_string($tone) || ! in_array($tone, ['active', 'busy', 'warn', 'danger', 'neutral'], true)) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (BoardFigureData $row): bool => ($row->tone ?? 'neutral') === $tone));
    }
}
