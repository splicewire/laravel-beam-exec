<?php

namespace Splicewire\Beam\Exec\Board;

use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;

/**
 * Everything a board card may read. Cards ({@see BoardMetric}) are handed one and read nothing else.
 *
 * The source is a request-scoped service rather than a request parameter, because a card's figures are drawn by
 * frame's summary route, which forwards nothing but the resource definition to a summary provider. Whatever decides
 * which source a request reads (the board's lens) binds it in the container; cards never choose.
 *
 * The package binds {@see LiveBoardSource}: central reads of the live estate, synthetic tenants included. The read
 * surface grows with the metrics that need it; every method must be answerable without a database by an in-memory
 * source, because a synthesized scenario is one.
 */
interface BoardSource
{
    /** Which reading this source is: `live` for {@see LiveBoardSource}. A card may print it; it must not branch on it. */
    public function lens(): string;

    /**
     * Every tenant this source can see, in no particular order.
     *
     * @return list<BoardTenant>
     */
    public function tenants(): array;
}
