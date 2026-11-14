<?php

namespace Splicewire\Beam\Exec\Board\Sources;

use Carbon\CarbonImmutable;
use Rushing\Commerce\Billing\BillingPeriod;
use Splicewire\Beam\Commerce\Billing\MeterMeasure;
use Splicewire\Beam\Commerce\Billing\MeterRegistry;
use Splicewire\Beam\Commerce\Billing\PeriodMeasures;
use Splicewire\Beam\Commerce\Billing\PlanPricer;
use Splicewire\Beam\Commerce\Revenue\MeasuredRevenueLedger;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Exec\Board\BoardProjection;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Board\Lens\BoardLensMode;
use Splicewire\Beam\Exec\Localization\ExecError;
use Splicewire\Beam\Exec\Localization\ExecInvalidArgumentException;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Roster\RosterMember;
use Splicewire\Beam\Synthetic\Roster\ScenarioProjector;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * The board read from a synthetic portfolio, in memory: the Scenario lens's source. Every read is answered from the
 * roster's curves, and none of them issues a query.
 *
 * ## Which months
 *
 * The roster's whole window, laid out so that it ENDS with the as-of month: a portfolio spanning 17 months reads the
 * 16 months before this one and this one. Each member starts its story `offsetMonths` into the window, the way the
 * materializer lays a roster's history out, so a scenario and a materialized copy of it tell the same story. A month
 * after the as-of month has no rows; a month before the window has none either.
 *
 * ## What each read is
 *
 * - **Tenants**: one per member present in the window, synthetic, active, created on the first day of its story.
 * - **Subscriptions**: one per tenant, on its blueprint's plan, from its first month to its churn month (or open).
 * - **Usage**: the curve's usage cost on the blueprint's meter, one row per month the member is present (a churned
 *   member's later months are zero, as the materializer records them). The quantity is the cost at the meter's
 *   declared unit cost, or at `beam.synthetic.usage_units_per_usd`: the materializer's rule.
 * - **Revenue**: each member-month with its plan in force, priced by beam-commerce's {@see PlanPricer} over hand-made
 *   {@see PeriodMeasures}: the commitment (one month), the seats active that month, no Brokered Tenants, and the
 *   month's usage. The recurring components ({@see MeasuredRevenueLedger::RECURRING}) are its MRR, the rest usage,
 *   so a scenario's MRR moves through the same {@see RevenueMovements} the live ledger feeds. No bills: billed and
 *   paid are null. The seats are the curve's seat count. The materializer seats a tenant from the first month the
 *   curve reaches a seat to the last it holds it; the kernel's seat ramp is monotone and drops to zero only after a
 *   churn, so that rule counts the same seats in every month, and a scenario prices what a materialized copy bills.
 * - **Posture**: none. A scenario models customers and money, not the estate; reading the live posture would put
 *   real (and query-costing) facts under the synthesized watermark, and inventing one would be a lie. The posture
 *   card reads "Unmeasured", and risk counts only the scenario's own tenants.
 *
 * The plans come in as a price list ({@see ScenarioPlan}), read by the resolver before the source is built. A
 * revenue read for a blueprint whose plan is not on it fails, naming the plan, rather than pricing it at zero.
 *
 * The portfolio's `churnRate` and `expansionRate` are not applied: they belong to the Projection lens. A scenario
 * replays its blueprints' curves, the same curves {@see ScenarioProjector} runs forward.
 */
final class SyntheticBoardSource implements BoardSource
{
    /** @var list<array{member: RosterMember, startedAt: CarbonImmutable, endedAt: ?CarbonImmutable, series: array<string, ScenarioMonthData>}> */
    private array $members = [];

    /**
     * @param  array<string, ScenarioPlan>  $plans  the price list, slug => plan
     */
    public function __construct(
        SyntheticRoster $roster,
        private readonly string $label,
        ScenarioExpander $expander,
        private readonly array $plans,
        private readonly CarbonImmutable $asOf,
        private readonly ?float $unitsPerUsd = null,
    ) {
        $length = $roster->length();
        $window = $roster->forwardMonths($asOf->startOfMonth()->subMonthsNoOverflow($length - 1), $length);

        foreach ($roster->members as $member) {
            $months = $roster->monthsFor($member, $window);

            if ($months === []) {
                continue;
            }

            $startedAt = CarbonImmutable::createFromFormat('!Y-m', $months[0])->startOfMonth();
            $endedAt = $member->blueprint->envelope($startedAt)->endedAt;
            $series = $roster->series($expander, $member, $months);

            $this->members[] = [
                'member' => $member,
                'startedAt' => $startedAt,
                'endedAt' => $endedAt,
                'series' => $series,
            ];
        }
    }

    public function lens(): string
    {
        return BoardLensMode::Scenario->value;
    }

    public function synthesizedFrom(): ?string
    {
        return $this->label;
    }

    /** Every month this source answers is read, not projected. */
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
        return array_map(fn (array $m): BoardTenant => new BoardTenant(
            id: $m['member']->tenantId,
            name: $m['member']->name,
            status: 'active',
            synthetic: true,
            createdAt: $m['startedAt'],
        ), $this->members);
    }

    public function subscriptions(): array
    {
        return array_map(fn (array $m): BoardSubscription => new BoardSubscription(
            tenantId: $m['member']->tenantId,
            planKey: $m['member']->blueprint->planSlug,
            planName: ($this->plans[$m['member']->blueprint->planSlug] ?? null)?->name ?? $m['member']->blueprint->planSlug,
            startedAt: $m['startedAt'],
            endedAt: $m['endedAt'],
        ), $this->members);
    }

    public function usage(string $month): array
    {
        $rows = [];

        foreach ($this->members as $m) {
            $point = $m['series'][$month] ?? null;

            if ($point === null || $month > $this->asOfMonth()) {
                continue;
            }

            $meter = $m['member']->blueprint->usageMeter;
            $rows[] = new BoardUsage($m['member']->tenantId, $month, $meter, $point->usageCostUsd, $this->quantity($meter, $point->usageCostUsd));
        }

        return $rows;
    }

    public function revenue(array $months): array
    {
        $unpriced = [];
        $rows = [];

        foreach ($this->members as $m) {
            foreach ($months as $month) {
                if (! $this->inForce($m, $month)) {
                    continue;
                }

                $plan = $this->plans[$m['member']->blueprint->planSlug] ?? null;

                if ($plan === null) {
                    $unpriced[$m['member']->blueprint->planSlug][] = $m['member']->blueprint->id;

                    continue;
                }

                $rows[] = $this->price($m, $month, $plan);
            }
        }

        if ($unpriced !== []) {
            throw new ExecInvalidArgumentException(ExecError::ScenarioUnpriced, [
                'scenario' => $this->label,
                'plans' => implode(', ', array_map(
                    fn (string $slug, array $ids): string => __('beam-exec::messages.scenario_plan_reference', ['slug' => $slug, 'blueprints' => implode(', ', array_unique($ids))]),
                    array_keys($unpriced),
                    $unpriced,
                )),
                'available' => $this->plans === [] ? __('beam-exec::messages.scenario_no_plans') : implode(', ', array_keys($this->plans)),
            ]);
        }

        usort($rows, fn (PartyPeriodRevenue $a, PartyPeriodRevenue $b): int => [$a->partyId, $a->period] <=> [$b->partyId, $b->period]);

        return $rows;
    }

    public function posture(): array
    {
        return [];
    }

    /** @param  array{member: RosterMember, startedAt: CarbonImmutable, endedAt: ?CarbonImmutable, series: array<string, ScenarioMonthData>}  $m */
    private function inForce(array $m, string $month): bool
    {
        return isset($m['series'][$month])
            && $month <= $this->asOfMonth()
            && ($m['endedAt'] === null || $month <= $m['endedAt']->format('Y-m'));
    }

    /** @param  array{member: RosterMember, startedAt: CarbonImmutable, endedAt: ?CarbonImmutable, series: array<string, ScenarioMonthData>}  $m */
    private function price(array $m, string $month, ScenarioPlan $plan): PartyPeriodRevenue
    {
        $tenant = $m['member']->tenantId;
        $meter = $m['member']->blueprint->usageMeter;
        $cost = $m['series'][$month]->usageCostUsd;
        $quantity = $this->quantity($meter, $cost);

        $measures = new PeriodMeasures(
            commitmentMonths: 1,
            activeSeats: $m['series'][$month]->seatCount,
            childTenants: 0,
            meters: [$meter => new MeterMeasure($cost, $quantity, [$tenant => ['cost_usd' => $cost, 'quantity' => $quantity]])],
            scope: [$tenant],
        );

        $recurring = 0.0;
        $usage = 0.0;
        foreach (PlanPricer::price($plan->components, $measures, BillingPeriod::fromString($month))->lineItems as $line) {
            if (in_array($line->componentType, MeasuredRevenueLedger::RECURRING, true)) {
                $recurring += $line->amountUsd;
            } else {
                $usage += $line->amountUsd;
            }
        }

        return new PartyPeriodRevenue($tenant, $month, $plan->slug, $recurring, $usage);
    }

    /** The materializer's quantity rule: the cost at the meter's declared unit cost, else at the configured ratio. */
    private function quantity(string $meter, float $costUsd): float
    {
        $unitCost = MeterRegistry::unitCostUsd($meter);

        if ($unitCost > 0) {
            return (float) round($costUsd / $unitCost);
        }

        return (float) round($costUsd * ($this->unitsPerUsd ?? (float) config('beam.synthetic.usage_units_per_usd', 1000)));
    }

    private function asOfMonth(): string
    {
        return $this->asOf->format('Y-m');
    }
}
