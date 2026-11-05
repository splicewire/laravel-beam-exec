<?php

namespace Splicewire\Beam\Exec\Tests\Support;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/** A card a host would declare: the shape every test card takes. */
#[BoardCard(key: 'pipeline', label: 'Pipeline', icon: 'Funnel', order: 5)]
final class PipelineCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        return [new BoardFigureData(id: 'tenants', label: 'Tenants', value: count($source->tenants()))];
    }
}

/** Declares the key a built-in card already holds. */
#[BoardCard(key: 'tenants', label: 'Impostor')]
final class DuplicateTenantsCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        return [];
    }
}

/** A metric with no attribute: it has no key. */
final class UndeclaredCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        return [];
    }
}

#[BoardCard(key: 'Revenue_Now', label: 'Bad key')]
final class BadKeyCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        return [];
    }
}

#[BoardCard(key: 'dashboard', label: 'Takes the dashboard')]
final class DashboardKeyCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        return [];
    }
}

/** Measures nothing: its summary must decline rather than draw an empty card. */
#[BoardCard(key: 'quiet', label: 'Quiet')]
final class EmptyCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        return [];
    }
}

/** Reads around the source: the conformance test must catch it. */
#[BoardCard(key: 'leaky', label: 'Leaky')]
final class LeakyCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        DB::select('select 1');

        return [new BoardFigureData(id: 'n', label: 'N', value: 1)];
    }
}

/** A card behind a narrower ability than the realm's. */
#[BoardCard(key: 'payroll', label: 'Payroll', ability: 'exec.payroll')]
final class GatedCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        return [new BoardFigureData(id: 'n', label: 'N', value: 1)];
    }
}
