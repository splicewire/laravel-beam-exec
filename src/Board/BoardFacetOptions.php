<?php

namespace Splicewire\Beam\Exec\Board;

/** Choice domains are read from the same request source as the cards, never a second model query. */
final class BoardFacetOptions
{
    public const HANDLES = ['beam_exec_tones', 'beam_exec_observations', 'beam_exec_tenant_statuses', 'beam_exec_tenant_plans'];

    /** @return list<array{value: string, label: string}> */
    public static function choices(string $handle, ?string $search = null): array
    {
        $values = match ($handle) {
            'beam_exec_tones' => ['active' => __('beam-exec::messages.facet_active'), 'busy' => __('beam-exec::messages.facet_busy'), 'warn' => __('beam-exec::messages.facet_warning'), 'danger' => __('beam-exec::messages.facet_danger'), 'neutral' => __('beam-exec::messages.facet_neutral')],
            'beam_exec_observations' => ['measured' => __('beam-exec::messages.facet_measured'), 'forecast' => __('beam-exec::messages.facet_forecast')],
            'beam_exec_tenant_statuses' => self::statuses(app(BoardSource::class)),
            'beam_exec_tenant_plans' => self::plans(app(BoardSource::class)),
            default => [],
        };
        $rows = [];
        foreach ($values as $value => $label) {
            if ($search === null || str_contains(mb_strtolower($label), mb_strtolower($search))) {
                $rows[] = ['value' => (string) $value, 'label' => $label];
            }
        }

        return $rows;
    }

    private static function statuses(BoardSource $source): array
    {
        if ($source->projection() !== null) {
            return [];
        }
        $values = [];
        foreach ($source->tenants() as $tenant) {
            $values[$tenant->status] = match ($tenant->status) {
                'pending' => __('beam-exec::messages.status_pending'),
                'provisioning' => __('beam-exec::messages.status_provisioning'),
                'active' => __('beam-exec::messages.status_active'),
                'failed' => __('beam-exec::messages.status_failed'),
                default => ucfirst($tenant->status),
            };
        }
        ksort($values);

        return $values;
    }

    private static function plans(BoardSource $source): array
    {
        if ($source->projection() !== null) {
            return [];
        }
        $values = [];
        foreach ($source->subscriptions() as $subscription) {
            if ($subscription->inForceAt($source->asOf())) {
                $values[$subscription->planKey] = $subscription->planName;
            }
        }
        ksort($values);

        return $values;
    }
}
