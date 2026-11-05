<?php

namespace Splicewire\Beam\Exec\Board;

/**
 * One tenant's usage on one meter in one month, as a board card sees it: the provider cost and the metered quantity.
 * It is what the platform spent on the tenant's behalf, not what the tenant was charged (that is revenue).
 */
final class BoardUsage
{
    /**
     * @param  string  $month  the calendar month, `YYYY-MM`
     * @param  string  $meter  the meter key (`openai.tokens`, `storage.gb`, …)
     * @param  float  $costUsd  the provider cost, in USD
     * @param  float  $quantity  the metered quantity, in the meter's own unit
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $month,
        public readonly string $meter,
        public readonly float $costUsd,
        public readonly float $quantity,
    ) {}
}
