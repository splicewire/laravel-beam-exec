<?php

namespace Splicewire\Beam\Exec\Tests\Support;

use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Posture\PostureSignal;

/** A board source with no database: what a synthesized scenario is, and what the conformance test measures against. */
final class InMemoryBoardSource implements BoardSource
{
    /**
     * @param  list<BoardTenant>  $tenants
     * @param  list<PostureSignal>  $posture
     */
    public function __construct(private readonly array $tenants = [], private readonly array $posture = []) {}

    public function lens(): string
    {
        return 'test';
    }

    public function tenants(): array
    {
        return $this->tenants;
    }

    public function posture(): array
    {
        return $this->posture;
    }
}
