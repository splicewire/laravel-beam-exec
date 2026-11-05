<?php

namespace Splicewire\Beam\Exec\Tests\Support;

use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardTenant;

/** A board source with no database: what a synthesized scenario is, and what the conformance test measures against. */
final class InMemoryBoardSource implements BoardSource
{
    /** @param  list<BoardTenant>  $tenants */
    public function __construct(private readonly array $tenants = []) {}

    public function lens(): string
    {
        return 'test';
    }

    public function tenants(): array
    {
        return $this->tenants;
    }
}
