<?php

namespace Splicewire\Beam\Exec\Board;

use Illuminate\Container\Container;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Particle\Backing\Unpaged;

/**
 * One board card as a resource: its figures, one {@see BoardFigureData} row each, measured through the request's
 * {@see BoardSource}.
 *
 * It is an INSTANCE backing because it carries the card's key, and a backing class-string cannot: every card shares
 * this class. The same reason, and the same shape, as beam-ux's realm `DashboardBacking`.
 *
 * Model-less, streams-only and {@see Unpaged}: a card is a handful of figures, one screen. No `ResolvesRecord`
 * (the resource is `showable: false`, a figure has no page of its own) and no `WritesRecords` (a figure is
 * measured, never written).
 *
 * ⚠️ Not memoized. This instance lives in the particle registry for the life of the process, so a memo would serve
 * the first request's figures, and the first request's lens, to every later one. Each read measures afresh.
 */
final class BoardCardBacking implements Unpaged
{
    public function __construct(
        public readonly string $card,
    ) {}

    /**
     * Every figure, one page. `$filters` is ignored (the backing declares no filter vocabulary) and `$perPage` by
     * the {@see Unpaged} contract.
     */
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $rows = $this->figures();

        return new CursorPaginator($rows, max(1, count($rows)), null, ['parameters' => ['id']]);
    }

    /** @return list<BoardFigureData> */
    public function figures(): array
    {
        $container = Container::getInstance();
        $cards = $container->make(BoardCardRegistry::class);
        $class = $cards->tryResolve($this->card);

        if (! is_string($class)) {
            return []; // the card left the registry: an honest absence, never an invented zero
        }

        return array_values($cards->metric(BoardCardDefinition::of($class))->measure($container->make(BoardSource::class)));
    }
}
