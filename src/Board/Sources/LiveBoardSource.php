<?php

namespace Splicewire\Beam\Exec\Board\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Posture\PostureRegistry;
use Splicewire\Beam\Exec\Posture\PostureSignal;
use Splicewire\Beam\Tenancy\Tenant;
use Throwable;

/**
 * The board read from the live estate: central reads only, synthetic tenants included.
 *
 * Synthetic tenants are counted on purpose. A materialized synthetic tenant is meant to read like a real customer
 * on every live surface, the board included; excluding them is the truth reading, which the board's lens selects,
 * not this source.
 *
 * The tenant model is the host's (`tenancy.tenant_model`, which beam-tenancy seats on its own `Tenant` unless the
 * host chose one). It reads on the central connection, as every tenant model does.
 *
 * Posture is every contributor in {@see PostureRegistry}, asked in registration order. A contributor that throws is
 * reported as one failing signal, `contributor-errored`, so one broken source cannot blank the board.
 *
 * Both reads are memoized for the life of this object, which the container scopes to one request: two cards drawn
 * in one request read the roster once and ask each contributor once.
 */
final class LiveBoardSource implements BoardSource
{
    public const CONTRIBUTOR_ERRORED = 'contributor-errored';

    /** @var list<BoardTenant>|null */
    private ?array $tenants = null;

    /** @var list<PostureSignal>|null */
    private ?array $posture = null;

    public function __construct(private readonly PostureRegistry $contributors) {}

    public function lens(): string
    {
        return 'live';
    }

    public function tenants(): array
    {
        /** @var class-string<Model> $model */
        $model = config('tenancy.tenant_model') ?: Tenant::class;

        return $this->tenants ??= $model::query()->get()->map(fn (Model $tenant): BoardTenant => new BoardTenant(
            id: (string) $tenant->getKey(),
            name: (string) ($tenant->name ?? $tenant->getKey()),
            status: (string) ($tenant->provisioning_status ?? ''),
            synthetic: (bool) ($tenant->synthetic ?? false),
            suspended: ($tenant->suspended_at ?? null) !== null,
            createdAt: $tenant->created_at !== null ? CarbonImmutable::parse($tenant->created_at) : null,
        ))->values()->all();
    }

    public function posture(): array
    {
        if ($this->posture !== null) {
            return $this->posture;
        }

        $signals = [];

        foreach ($this->contributors->contributorKeys() as $key) {
            try {
                foreach ($this->contributors->contributor($key)->signals() as $signal) {
                    $signals[] = $signal->from($key);
                }
            } catch (Throwable $e) {
                $signals[] = PostureSignal::fail(
                    self::CONTRIBUTOR_ERRORED,
                    sprintf('The posture contributor `%s` threw %s: %s', $key, $e::class, $e->getMessage()),
                )->from($key);
            }
        }

        return $this->posture = $signals;
    }
}
