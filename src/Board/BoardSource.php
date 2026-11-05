<?php

namespace Splicewire\Beam\Exec\Board;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
use Splicewire\Beam\Exec\Board\Sources\SyntheticBoardSource;
use Splicewire\Beam\Exec\Posture\PostureRegistry;
use Splicewire\Beam\Exec\Posture\PostureSignal;

/**
 * Everything a board card may read. Cards ({@see BoardMetric}) are handed one and read nothing else.
 *
 * The source is a request-scoped service rather than a request parameter, because a card's figures are drawn by
 * frame's summary route, which forwards nothing but the resource definition to a summary provider. Whatever decides
 * which source a request reads (the board's lens) binds it in the container; cards never choose.
 *
 * The board's lens ({@see BoardLensResolver}) binds {@see LiveBoardSource}, central reads of the estate reading
 * `live` (synthetic tenants included) or `truth` (real tenants only), or {@see SyntheticBoardSource}, a synthetic
 * portfolio read in memory. The read surface grows with the metrics that need it; every method must be answerable
 * without a database by an in-memory source, because a synthesized scenario is one.
 *
 * Every read is already narrowed to the tenants {@see tenants()} returns: a subscription or a usage row of a tenant
 * this source does not see is not returned either. A card never filters by lens itself.
 */
interface BoardSource
{
    /**
     * Which reading this source is: `live` or `truth` for {@see LiveBoardSource}, `scenario` for
     * {@see SyntheticBoardSource}. A card may print it; it must not branch on it.
     */
    public function lens(): string;

    /**
     * What this source synthesizes its figures from (a scenario's name), or null when it reads the estate.
     *
     * A non-null answer puts the synthesized watermark ({@see BoardWatermark}) on every figure every card draws from
     * this source. Cards never read it: the card registry stamps the watermark on the way out, so no card can leave
     * it off.
     */
    public function synthesizedFrom(): ?string;

    /**
     * The moment the board reads as of. "This month" on a card is the month of this instant, never the wall clock,
     * so a scenario or a projection can read a month other than today's.
     */
    public function asOf(): CarbonImmutable;

    /**
     * Every tenant this source can see, in no particular order.
     *
     * @return list<BoardTenant>
     */
    public function tenants(): array;

    /**
     * Every plan subscription of a tenant this source can see, ended ones included, in no particular order. A
     * subscription to anything other than a platform plan (a marketplace listing) is not one.
     *
     * @return list<BoardSubscription>
     */
    public function subscriptions(): array;

    /**
     * Metered usage in one calendar month (`YYYY-MM`): one row per tenant and meter that recorded any, attributed to
     * the tenant that drove it (a Brokered Tenant's usage is its own row, not its broker's).
     *
     * @return list<BoardUsage>
     */
    public function usage(string $month): array;

    /**
     * Each billing party's revenue in each of `$months` (`YYYY-MM`): a row per party and month with a plan in force,
     * the shape every ledger shares, so the board's revenue figures come from beam-commerce's one aggregator
     * ({@see RevenueMovements}) whatever the source. Only parties among {@see tenants()} appear. A month after the
     * as-of month has no rows.
     *
     * @param  list<string>  $months
     * @return list<PartyPeriodRevenue>
     */
    public function revenue(array $months): array;

    /**
     * How the estate stands: every signal from every posture contributor ({@see PostureRegistry}), each stamped with
     * the key of the contributor that produced it, in contributor order.
     *
     * @return list<PostureSignal>
     */
    public function posture(): array;
}
