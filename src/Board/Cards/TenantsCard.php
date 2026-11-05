<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Carbon\CarbonImmutable;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/**
 * The board's headcount: how many tenants there are, how many are live, how many arrived this month, and how many of
 * them are synthetic.
 *
 * It is the reference card: the smallest card that reads through the {@see BoardSource} and nothing else. The
 * growth, usage, plan-mix, revenue, posture and risk cards follow its shape.
 *
 * `synthetic` is shown because the live board counts synthetic tenants: a reader must be able to see how much of the
 * headcount is not real customers.
 */
#[BoardCard(
    key: 'tenants',
    label: 'Tenants',
    icon: 'Building2',
    order: 10,
)]
final class TenantsCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        $tenants = $source->tenants();
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $count = fn (callable $test): int => count(array_filter($tenants, $test));

        return [
            new BoardFigureData(id: 'total', label: 'Tenants', value: count($tenants)),
            new BoardFigureData(
                id: 'active',
                label: 'Active',
                value: $count(fn (BoardTenant $t): bool => $t->isActive() && ! $t->suspended),
                tone: SummaryFigureData::TONE_ACTIVE,
            ),
            new BoardFigureData(
                id: 'new',
                label: 'New this month',
                value: $count(fn (BoardTenant $t): bool => $t->createdAt !== null && $t->createdAt->gte($monthStart)),
                tone: SummaryFigureData::TONE_BUSY,
            ),
            new BoardFigureData(
                id: 'synthetic',
                label: 'Synthetic',
                value: $count(fn (BoardTenant $t): bool => $t->synthetic),
            ),
        ];
    }
}
