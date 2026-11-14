<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\Figures;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/**
 * Who is on which plan, as of {@see BoardSource::asOf()}: how many tenants hold a plan, one figure per plan (largest
 * first), how much of the book is on annual terms, how much is paused, and how many direct tenants hold no plan.
 *
 * A plan figure counts tenants, not subscriptions, so a tenant is never on one plan twice. A Brokered Tenant holds no
 * subscription of its own (its broker is billed for it), so it is never "without a plan". The plans' prices are the
 * revenue card's.
 */
#[BoardCard(
    key: 'plan-mix',
    label: 'Plan mix',
    icon: 'PieChart',
    order: 30,
)]
final class PlanMixCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        $now = $source->asOf();
        $inForce = array_values(array_filter(
            $source->subscriptions(),
            fn (BoardSubscription $s): bool => $s->inForceAt($now),
        ));

        $subscribed = [];
        $byPlan = [];
        $names = [];
        $annual = [];
        $paused = [];

        foreach ($inForce as $subscription) {
            $subscribed[$subscription->tenantId] = true;
            $byPlan[$subscription->planKey][$subscription->tenantId] = true;
            $names[$subscription->planKey] = $subscription->planName;

            if ($subscription->isAnnual()) {
                $annual[$subscription->tenantId] = true;
            }
            if ($subscription->paused) {
                $paused[$subscription->tenantId] = true;
            }
        }

        $planCounts = array_map('count', $byPlan);
        // Largest plan first; a tie reads in plan-key order, the same every time.
        uksort($planCounts, fn (string $a, string $b): int => [$planCounts[$b], $a] <=> [$planCounts[$a], $b]);

        $unsubscribed = count(array_filter(
            $source->tenants(),
            fn (BoardTenant $t): bool => ! $t->isBrokered() && ! isset($subscribed[$t->id]),
        ));

        $figures = [
            new BoardFigureData(
                id: 'subscribed',
                label: __('beam-exec::messages.labels_on_a_plan'),
                value: count($subscribed),
                tone: SummaryFigureData::TONE_ACTIVE,
            ),
        ];

        foreach ($planCounts as $key => $count) {
            $figures[] = new BoardFigureData(id: "plan-{$key}", label: $names[$key], value: $count);
        }

        $figures[] = new BoardFigureData(
            id: 'annual-share',
            label: __('beam-exec::messages.labels_on_annual_terms'),
            value: Figures::percent(Figures::share(count($annual), count($subscribed))),
        );
        $figures[] = new BoardFigureData(
            id: 'paused',
            label: __('beam-exec::messages.labels_paused'),
            value: count($paused),
            tone: $paused === [] ? null : SummaryFigureData::TONE_WARN,
        );
        $figures[] = new BoardFigureData(id: 'unsubscribed', label: __('beam-exec::messages.labels_without_a_plan'), value: $unsubscribed);

        return $figures;
    }
}
