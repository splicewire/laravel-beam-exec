<?php

namespace Splicewire\Beam\Exec\Board;

use Carbon\CarbonImmutable;

/**
 * One tenant as a board card sees it: the facts a growth figure needs, and nothing a card could use to reach back
 * into the database. A {@see BoardSource} builds these; an in-memory source builds them from a scenario.
 */
final class BoardTenant
{
    /**
     * @param  string  $status  the provisioning status (`pending`, `provisioning`, `active`, `failed`)
     * @param  bool  $synthetic  whether the tenant is a synthetic tenant (generated, not a real customer)
     * @param  bool  $suspended  whether the tenant is suspended (orthogonal to `$status`)
     * @param  CarbonImmutable|null  $createdAt  when the tenant was created; null when the source does not know
     * @param  string|null  $brokerId  the broker this is a Brokered Tenant of; null = a direct tenant. A Brokered
     *                                 Tenant holds no subscription of its own: its broker is billed for it.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $status,
        public readonly bool $synthetic = false,
        public readonly bool $suspended = false,
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly ?string $brokerId = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBrokered(): bool
    {
        return $this->brokerId !== null;
    }
}
