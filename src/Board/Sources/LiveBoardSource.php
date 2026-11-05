<?php

namespace Splicewire\Beam\Exec\Board\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Commerce\CentralUsageSummary;
use Splicewire\Beam\Commerce\Revenue\MeasuredRevenueLedger;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Commerce\Subscription;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardSubscription;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\BoardUsage;
use Splicewire\Beam\Exec\Posture\PostureRegistry;
use Splicewire\Beam\Exec\Posture\PostureSignal;
use Splicewire\Beam\Tenancy\Synthetic\SyntheticReporting;
use Splicewire\Beam\Tenancy\Tenant;
use Throwable;

/**
 * The board read from the estate's central tables, in one of two readings:
 *
 *  - **live** ({@see live()}): every tenant, synthetic tenants included. A materialized synthetic tenant is meant to
 *    read like a real customer on every live surface, the board included.
 *  - **truth** ({@see truth()}): real tenants only, through the tenant model's `notSynthetic()` scope, the scope
 *    beam-tenancy's `reportable()` applies while the reporting toggle excludes synthetic tenants.
 *
 * {@see reportable()} picks between them the way `Tenant::reportable()` does: truth while
 * {@see SyntheticReporting::excludes()} is true, live otherwise. It is the package's default binding, so flipping the
 * toggle moves the board to the truth on the next request, with no redeploy.
 *
 * Every read is central and goes through the tenant set: a subscription or a usage row whose tenant this source does
 * not see is dropped, so a truth board never counts a synthetic tenant's plan or spend.
 *
 * The tenant model is the host's (`tenancy.tenant_model`, which beam-tenancy seats on its own `Tenant` unless the
 * host chose one). It reads on the central connection, as every tenant model does.
 *
 * Posture is every contributor in {@see PostureRegistry}, asked in registration order. Posture is not narrowed by the
 * reading: it describes the estate, not its customers. A contributor that throws is reported as one failing signal,
 * `contributor-errored`, so one broken source cannot blank the board.
 *
 * Each read is memoized for the life of this object, which the container scopes to one request: two cards drawn in
 * one request read the roster once and ask each contributor once.
 */
final class LiveBoardSource implements BoardSource
{
    public const CONTRIBUTOR_ERRORED = 'contributor-errored';

    /** @var list<BoardTenant>|null */
    private ?array $tenants = null;

    /** @var list<PostureSignal>|null */
    private ?array $posture = null;

    /** @var array<string, list<PartyPeriodRevenue>> */
    private array $revenue = [];

    /** @var list<BoardSubscription>|null */
    private ?array $subscriptions = null;

    /** @var array<string, list<BoardUsage>> */
    private array $usage = [];

    private ?CarbonImmutable $asOf = null;

    /** The registry is resolved on first {@see posture()} when not given, so the static readings need no container. */
    public function __construct(private readonly bool $truth = false, private ?PostureRegistry $contributors = null) {}

    public static function live(): self
    {
        return new self(truth: false);
    }

    public static function truth(): self
    {
        return new self(truth: true);
    }

    /** Truth while the reporting toggle excludes synthetic tenants, live otherwise. */
    public static function reportable(): self
    {
        return new self(truth: app(SyntheticReporting::class)->excludes());
    }

    public function lens(): string
    {
        return $this->truth ? 'truth' : 'live';
    }

    public function asOf(): CarbonImmutable
    {
        return $this->asOf ??= CarbonImmutable::now();
    }

    public function tenants(): array
    {
        if ($this->tenants !== null) {
            return $this->tenants;
        }

        $model = $this->tenantModel();
        $query = $model::query();
        $scoped = $this->truth && (new $model)->hasNamedScope('notSynthetic');

        if ($scoped) {
            $query->notSynthetic();
        }

        $tenants = $query->get()->map(fn (Model $tenant): BoardTenant => new BoardTenant(
            id: (string) $tenant->getKey(),
            name: (string) ($tenant->name ?? $tenant->getKey()),
            status: (string) ($tenant->provisioning_status ?? ''),
            synthetic: (bool) ($tenant->synthetic ?? false),
            suspended: ($tenant->suspended_at ?? null) !== null,
            createdAt: $tenant->created_at !== null ? CarbonImmutable::parse($tenant->created_at) : null,
            brokerId: ($tenant->parent_tenant_id ?? null) !== null ? (string) $tenant->parent_tenant_id : null,
        ));

        // A host tenant model without beam-tenancy's scope still reads the truth: the flag is on every row.
        if ($this->truth && ! $scoped) {
            $tenants = $tenants->reject(fn (BoardTenant $tenant): bool => $tenant->synthetic);
        }

        return $this->tenants = $tenants->values()->all();
    }

    public function subscriptions(): array
    {
        if ($this->subscriptions !== null) {
            return $this->subscriptions;
        }

        $visible = $this->visibleTenantIds();

        return $this->subscriptions = Subscription::query()
            ->with('plan')
            ->whereNotNull('plan_id')
            ->where('party_type', (new ($this->tenantModel()))->getMorphClass())
            ->get()
            ->filter(fn (Subscription $subscription): bool => isset($visible[(string) $subscription->party_id]) && $subscription->plan !== null)
            ->map(fn (Subscription $subscription): BoardSubscription => new BoardSubscription(
                tenantId: (string) $subscription->party_id,
                planKey: (string) $subscription->plan->slug,
                planName: (string) $subscription->plan->name,
                startedAt: CarbonImmutable::parse($subscription->started_at),
                endedAt: $subscription->ended_at !== null ? CarbonImmutable::parse($subscription->ended_at) : null,
                commitmentMonths: (int) $subscription->commitment_months,
                paused: $subscription->paused_at !== null,
            ))
            ->values()
            ->all();
    }

    /**
     * One month's usage summaries, attributed once each to the first tenant of theirs this source sees (a summary
     * normally has a single owner). A summary from before the per-meter column carries only token spend, so it reads
     * as `openai.tokens`, the fallback the billing components apply.
     */
    public function usage(string $month): array
    {
        if (isset($this->usage[$month])) {
            return $this->usage[$month];
        }

        $visible = $this->visibleTenantIds();
        $rows = [];

        foreach (CentralUsageSummary::with('tenants:id')->where('month', $month)->get() as $summary) {
            $owner = $summary->tenants->first(fn (Model $tenant): bool => isset($visible[(string) $tenant->getKey()]));

            if ($owner === null) {
                continue;
            }

            $meters = $summary->meters ?? [
                'openai.tokens' => ['cost_usd' => (float) $summary->total_cost_usd, 'quantity' => (float) $summary->total_tokens],
            ];

            foreach ($meters as $meter => $measure) {
                $key = $owner->getKey()."\0".$meter;
                $rows[$key] ??= ['tenant' => (string) $owner->getKey(), 'meter' => (string) $meter, 'cost' => 0.0, 'quantity' => 0.0];
                $rows[$key]['cost'] += (float) ($measure['cost_usd'] ?? 0);
                $rows[$key]['quantity'] += (float) ($measure['quantity'] ?? 0);
            }
        }

        return $this->usage[$month] = array_values(array_map(
            fn (array $row): BoardUsage => new BoardUsage($row['tenant'], $month, $row['meter'], $row['cost'], $row['quantity']),
            $rows,
        ));
    }

    /**
     * The live revenue ledger, for the tenants this source sees: measured from the central tables and priced by the
     * pricer billing composes through. Billed and paid amounts are filled, because the live estate has bills.
     */
    public function revenue(array $months): array
    {
        $key = implode(',', $months);

        return $this->revenue[$key] ??= app(MeasuredRevenueLedger::class)->read($months, array_keys($this->visibleTenantIds()));
    }

    /** @return array<string, true> */
    private function visibleTenantIds(): array
    {
        return array_fill_keys(array_map(fn (BoardTenant $tenant): string => $tenant->id, $this->tenants()), true);
    }

    /** @return class-string<Model> */
    private function tenantModel(): string
    {
        return config('tenancy.tenant_model') ?: Tenant::class;
    }

    public function posture(): array
    {
        if ($this->posture !== null) {
            return $this->posture;
        }

        $signals = [];
        $contributors = $this->contributors ??= app(PostureRegistry::class);

        foreach ($contributors->contributorKeys() as $key) {
            try {
                foreach ($contributors->contributor($key)->signals() as $signal) {
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
