<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\FacetedBoardMetric;
use Splicewire\Beam\Exec\Board\FiltersByTone;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Posture\PostureSignal;

/**
 * What needs someone's attention, counted from the signals the board already has: suspended tenants, tenants whose
 * provisioning failed, and failing posture checks. The headline is their sum.
 *
 * Each is a count of things that are wrong now, not a forecast or a weighted score, so the card can be read without
 * knowing how it was computed. Warnings are not risks; the posture card shows them.
 *
 * The live board counts synthetic tenants, so a suspended synthetic tenant is a risk on the live reading too; the
 * truth reading leaves it out.
 *
 * Failing checks are counted only from a posture that measured something. When no posture signal is conclusive
 * (no report has landed yet, or nothing had anything to look at), the figure reads `Unmeasured`, and the headline
 * keeps its count of the tenant risks without the calm tone: measured nothing is never clean.
 */
#[BoardCard(
    key: 'risk',
    label: 'Risk',
    icon: 'TriangleAlert',
    order: 50,
)]
final class RiskCard implements FacetedBoardMetric
{
    use FiltersByTone;

    public function measure(BoardSource $source): array
    {
        $tenants = $source->tenants();

        $suspended = count(array_filter($tenants, fn (BoardTenant $t): bool => $t->suspended));
        $failed = count(array_filter($tenants, fn (BoardTenant $t): bool => $t->status === 'failed'));
        $posture = $source->posture();
        $measured = array_filter($posture, fn (PostureSignal $s): bool => $s->conclusive) !== [];
        $failing = count(array_filter($posture, fn (PostureSignal $s): bool => $s->failing()));

        $tone = fn (int $n): ?string => $n > 0 ? SummaryFigureData::TONE_DANGER : null;
        $open = $suspended + $failed + $failing;
        $calm = $measured ? SummaryFigureData::TONE_ACTIVE : null;

        return [
            new BoardFigureData(
                id: 'open',
                label: 'Open risks',
                value: $open,
                tone: $open > 0 ? SummaryFigureData::TONE_DANGER : $calm,
            ),
            new BoardFigureData(id: 'suspended', label: 'Suspended tenants', value: $suspended, tone: $tone($suspended)),
            new BoardFigureData(id: 'failed-provisioning', label: 'Failed provisioning', value: $failed, tone: $tone($failed)),
            new BoardFigureData(
                id: 'failing-checks',
                label: 'Failing checks',
                value: $measured ? $failing : 'Unmeasured',
                tone: $tone($failing),
            ),
        ];
    }
}
