<?php

namespace Splicewire\Beam\Exec\Board\Cards;

use Carbon\CarbonInterface;
use Rushing\Doctor\DoctorStatus;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\FacetedBoardMetric;
use Splicewire\Beam\Exec\Board\FiltersByTone;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Posture\PostureRegistry;
use Splicewire\Beam\Exec\Posture\PostureSignal;

/**
 * How the estate stands, in one word and three counts: the worst status any posture contributor reports, then how
 * many checks pass, warn and fail, and how many measured nothing.
 *
 * The signals come from the source's {@see BoardSource::posture()}, which the live source fills from every
 * contributor in {@see PostureRegistry}: the host's readiness audits by default, plus whatever an installed package
 * contributes.
 *
 * A check that had nothing to look at is counted as unmeasured, never as passing, and does not raise the headline:
 * a board with nothing measured reads `Unmeasured`, not `Pass`.
 *
 * When a contributor serves a stored report (the doctor's does), the card adds `measured`: the age of the oldest
 * reading, relative to the source's as-of moment, so a posture read from a report is never mistaken for one taken now.
 */
#[BoardCard(
    key: 'posture',
    label: 'Posture',
    icon: 'ShieldCheck',
    order: 40,
)]
final class PostureCard implements FacetedBoardMetric
{
    use FiltersByTone;

    public function measure(BoardSource $source): array
    {
        $signals = $source->posture();
        $count = fn (callable $test): int => count(array_filter($signals, $test));

        $passing = $count(fn (PostureSignal $s): bool => $s->passing());
        $warnings = $count(fn (PostureSignal $s): bool => $s->warning());
        $failing = $count(fn (PostureSignal $s): bool => $s->failing());
        $unmeasured = count($signals) - $passing - $warnings - $failing;

        [$headline, $tone] = match (true) {
            $failing > 0 => [ucfirst(DoctorStatus::Fail->value), SummaryFigureData::TONE_DANGER],
            $warnings > 0 => [ucfirst(DoctorStatus::Warn->value), SummaryFigureData::TONE_WARN],
            $passing > 0 => [ucfirst(DoctorStatus::Pass->value), SummaryFigureData::TONE_ACTIVE],
            default => ['Unmeasured', null],
        };

        return [
            new BoardFigureData(id: 'status', label: 'Posture', value: $headline, tone: $tone),
            new BoardFigureData(id: 'passing', label: 'Passing', value: $passing, tone: SummaryFigureData::TONE_ACTIVE),
            new BoardFigureData(
                id: 'warnings',
                label: 'Warnings',
                value: $warnings,
                tone: $warnings > 0 ? SummaryFigureData::TONE_WARN : null,
            ),
            new BoardFigureData(
                id: 'failing',
                label: 'Failing',
                value: $failing,
                tone: $failing > 0 ? SummaryFigureData::TONE_DANGER : null,
            ),
            new BoardFigureData(id: 'unmeasured', label: 'Unmeasured', value: $unmeasured),
            ...$this->age($signals, $source),
        ];
    }

    /**
     * @param  list<PostureSignal>  $signals
     * @return list<BoardFigureData>
     */
    private function age(array $signals, BoardSource $source): array
    {
        $times = array_values(array_filter(array_map(fn (PostureSignal $s) => $s->measuredAt, $signals)));

        if ($times === []) {
            return [];
        }

        $oldest = min($times);

        return [new BoardFigureData(
            id: 'measured',
            label: 'Measured',
            value: $oldest->diffForHumans($source->asOf(), CarbonInterface::DIFF_RELATIVE_TO_NOW),
        )];
    }
}
