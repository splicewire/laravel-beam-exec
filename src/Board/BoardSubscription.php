<?php

namespace Splicewire\Beam\Exec\Board;

use Carbon\CarbonImmutable;

/**
 * One plan subscription as a board card sees it: who holds which plan, over which window. A plain value, so a card
 * cannot reach back into the database through it; an in-memory source builds these from a scenario.
 */
final class BoardSubscription
{
    /**
     * @param  string  $tenantId  the subscribing tenant (the billing party)
     * @param  string  $planKey  the plan's stable key (its slug); the figure id a plan-mix card draws
     * @param  string  $planName  the plan's display name
     * @param  CarbonImmutable  $startedAt  when the subscription took effect
     * @param  CarbonImmutable|null  $endedAt  when it lapsed or will lapse; null = open-ended
     * @param  int  $commitmentMonths  the term commitment (12 or more is an annual commitment)
     * @param  bool  $paused  whether billing is on hold now (a pause, not a lapse)
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $planKey,
        public readonly string $planName,
        public readonly CarbonImmutable $startedAt,
        public readonly ?CarbonImmutable $endedAt = null,
        public readonly int $commitmentMonths = 1,
        public readonly bool $paused = false,
    ) {}

    /** In force at `$at`: started on or before it, and not yet ended (an end in the future is a scheduled lapse). */
    public function inForceAt(CarbonImmutable $at): bool
    {
        return $this->startedAt->lte($at) && ($this->endedAt === null || $this->endedAt->gt($at));
    }

    public function isAnnual(): bool
    {
        return $this->commitmentMonths >= 12;
    }
}
