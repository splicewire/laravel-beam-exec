<?php

namespace Splicewire\Beam\Exec\Tests\Support;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Exec\Board\BoardProjection;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Posture\PostureSignal;

/** A board source with no database: what a synthesized scenario is, and what the conformance test measures against. */
final class InMemoryBoardSource implements BoardSource
{
    private readonly CarbonImmutable $asOf;

    /**
     * @param  list<BoardTenant>  $tenants
     * @param  list<BoardSubscription>  $subscriptions
     * @param  list<BoardUsage>  $usage  every month's rows; {@see usage()} answers one month of them
     * @param  list<PartyPeriodRevenue>  $revenue  every month's rows; {@see revenue()} answers the months asked
     * @param  list<PostureSignal>  $posture
     */
    public function __construct(
        private readonly array $tenants = [],
        private readonly array $subscriptions = [],
        private readonly array $usage = [],
        ?CarbonImmutable $asOf = null,
        private readonly array $revenue = [],
        private readonly array $posture = [],
        private readonly ?string $synthesizedFrom = null,
    ) {
        $this->asOf = $asOf ?? CarbonImmutable::parse('2026-08-20 12:00:00');
    }

    public function lens(): string
    {
        return 'test';
    }

    public function synthesizedFrom(): ?string
    {
        return $this->synthesizedFrom;
    }

    public function projection(): ?BoardProjection
    {
        return null;
    }

    public function asOf(): CarbonImmutable
    {
        return $this->asOf;
    }

    public function tenants(): array
    {
        return $this->tenants;
    }

    public function subscriptions(): array
    {
        return $this->subscriptions;
    }

    public function usage(string $month): array
    {
        return array_values(array_filter($this->usage, fn (BoardUsage $u): bool => $u->month === $month));
    }

    public function revenue(array $months): array
    {
        return array_values(array_filter($this->revenue, fn (PartyPeriodRevenue $r): bool => in_array($r->period, $months, true)));
    }

    public function posture(): array
    {
        return $this->posture;
    }
}
