<?php

namespace Splicewire\Beam\Exec\Realm;

use Splicewire\Beam\Exec\Concerns\WiresExecRealm;
use Splicewire\Beam\Realm\Attributes\Realm;

/**
 * The realm marker for the board: the `exec` realm at `/exec`.
 *
 * A plain class whose only job is to carry the `#[Realm]` attribute. Beam reflects it from
 * `beam.core.realms.classes` and registers the definition onto the `RealmRegistry`, next to the four base
 * realms. {@see WiresExecRealm} lists it there.
 *
 * It is **central**: the board reads across every tenant, so it is never tenant-scoped. It has **no guard**,
 * because a board member is not a root operator; the door is the realm gate, `entitlement:exec.view`, which
 * beam-accounts composes from an `operator` or an `exec` realm grant.
 */
#[Realm(key: self::KEY, routeBase: self::ROUTE_BASE, central: true)]
final class ExecRealm
{
    /** The realm's wire key: the manifest route default, the resource-realm map key, the dashboard `exec-dashboard`. */
    public const KEY = 'exec';

    /** Where the realm's console mounts. */
    public const ROUTE_BASE = '/exec';

    /** The entitlement the realm is gated on (`beam.core.realms.exec.gate.entitlement`). */
    public const ENTITLEMENT = 'exec.view';

    /**
     * The entitlement that saves and deletes scenarios with the scenario builder (a stored portfolio is shared board
     * state). Checked together with {@see ENTITLEMENT}. beam-accounts' default resolver composes it from the
     * `operator` realm grant only: the `exec` grant stays a read seat.
     */
    public const SCENARIO_MANAGE = 'exec.scenario.manage';

    /** The realm's home route name: the host's `Route::beamRealmConsole('exec', …)` mounts it. */
    public const HOME = 'exec.home';

    /** The board's nav section key. */
    public const SECTION = 'board';
}
