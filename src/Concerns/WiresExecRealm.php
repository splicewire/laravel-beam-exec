<?php

namespace Splicewire\Beam\Exec\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Exec\Realm\ExecRealm;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;

/**
 * One concern of {@see BeamExecServiceProvider}: the `exec` realm, its profile, its gate and its `board` seat.
 *
 * ## Why the realm is written in `register()`
 *
 * Beam reads `beam.core.realms.classes` in its own boot, and beam-ux's realm-dashboard link (boot order 58)
 * registers one `{realm}-dashboard` resource per realm it finds then. A host's route file reads the realm's
 * routes (`RouteContextProjector::hrefs('exec')`) right after the last provider boots, before any
 * `booted()` callback. So the realm has to be in config before ANY provider boots: a `register` link. Written
 * later, the dashboard leaf would be missing from the route file and `/exec/dashboard` would 404.
 *
 * ## What it writes
 *
 *  - `beam.core.realms.classes` gains {@see ExecRealm}, appended to whatever the host lists.
 *  - `beam.core.realms.exec`, the realm profile, defaults to `{label: 'Board', home: 'exec.home',
 *    gate: {entitlement: 'exec.view'}}`. A host's own declaration wins key by key. The gate is what makes
 *    the dashboard's `policy:` `entitlement:exec.view` rather than the central-realm default `os.operate`.
 *  - `beam.core.entitlements.keys` gains `exec.view`, so beam defines the `entitlement:exec.view` Gate
 *    ability. Without it, every `can:entitlement:exec.view` check is an undefined ability and denies.
 *
 * Who holds `exec.view` is not decided here. beam-accounts' `DefaultEntitlementResolver` composes it from an
 * `operator` realm grant or an `exec` realm grant (the board seat).
 */
trait WiresExecRealm
{
    /** The realm profile's package defaults. A host's `beam.core.realms.exec` replaces any of them. */
    private const EXEC_PROFILE = [
        'label' => 'Board',
        'home' => ExecRealm::HOME,
        'surface' => 'app',
        'gate' => ['entitlement' => ExecRealm::ENTITLEMENT, 'mode' => 'hard'],
    ];

    #[Chained('register', order: 10)]
    protected function registerExecRealm(): void
    {
        $classes = (array) config('beam.core.realms.classes', []);

        if (! in_array(ExecRealm::class, $classes, true)) {
            $classes[] = ExecRealm::class;
        }

        $keys = (array) config('beam.core.entitlements.keys', []);

        if (! in_array(ExecRealm::ENTITLEMENT, $keys, true)) {
            $keys[] = ExecRealm::ENTITLEMENT;
        }

        config([
            'beam.core.realms.classes' => array_values($classes),
            'beam.core.realms.'.ExecRealm::KEY => array_replace_recursive(
                self::EXEC_PROFILE,
                (array) config('beam.core.realms.'.ExecRealm::KEY, []),
            ),
            'beam.core.entitlements.keys' => array_values($keys),
        ]);
    }

    /**
     * The `board` seat: the exec realm's one top-level nav section, whose children are the board's cards.
     *
     * Its href is the section's own path, `/exec/board`, never the dashboard leaf's `/exec/dashboard`. The
     * console matches a seat's href as a section index before it matches a resource leaf, and excludes only
     * seats whose route name is a bound leaf. A seat is `board.section`, never bound, so a seat on the
     * dashboard's path drew the Board section's index there instead of the cards. The dashboard stays its
     * own top-level leaf (beam-ux registers it per realm), beside this seat.
     *
     * Gated on `exec.view`, the realm's own entitlement, so the seat is never a softer door than the realm.
     * `bound()`-guarded like every package seat: whether beam is new enough to bind the registry is a fact
     * about the host.
     */
    #[Chained('boot', order: 10)]
    protected function bootBoardSection(): void
    {
        if (! $this->app->bound(NavSectionRegistry::class)) {
            return;
        }

        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: ExecRealm::SECTION,
            realm: ExecRealm::KEY,
            label: 'Board',
            icon: 'Presentation',
            href: ExecRealm::ROUTE_BASE.'/'.ExecRealm::SECTION,
            order: 10,
            entitlement: [ExecRealm::ENTITLEMENT],
            permission: null,
            audience: NavAudience::Product,
        ));
    }
}
