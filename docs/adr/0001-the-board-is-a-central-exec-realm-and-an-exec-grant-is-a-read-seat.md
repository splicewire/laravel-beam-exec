# ADR-0001 — The board is a central `exec` realm, and an exec grant is a read seat

**Status:** accepted
**Date:** 2026-11-05
**Repo:** `splicewire/laravel-beam-exec`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/exec-board/` (PLAN.md D1, D3, D9; tickets 02, 03, 04, 20).
Owner ruling 2026-11-05: "Accept all recommendations".
**Relates to:** `splicewire/laravel-beam docs/adr/0222-a-realm-dashboard-is-one-read-only-resource-per-realm-of-card-rows.md`
(the dashboard this realm reuses), `splicewire/laravel-beam docs/adr/0240-the-projected-nav-tree-is-closed-by-i5-i7-and-each-seat-resolves-one-gate.md`
(the seat's one gate).

## Context

A board-meeting dashboard (growth, revenue, usage, posture, risk) was asked for "as a Beam capability". It needs a
place to live that a board member can open without becoming an operator, and that any Beam host can mount. Beam
already has realms (`#[Realm]` markers in `beam.core.realms.classes`), a per-realm dashboard in beam-ux, and
entitlement-gated realm profiles. The question was whether the board needed beam-ux changes, a new package, or both.

## Decision

1. **Two new packages** (D1, D9). `splicewire/laravel-beam-synthetic` holds the synthetic-data and projection
   machinery; this package holds the board and requires synthetic, beam, beam-ux, beam-tenancy and beam-commerce.
   Neither lives in beam-tenancy, which may not require beam-commerce. Both fence out `splicewire/tower`,
   `splicewire/laravel-satellite` and `splicewire/laravel-tower-*` in `extra.package-topology`, and synthetic declares
   `neverReaches` this package, so the dependency can't invert into a cycle.
2. **The board is a central realm, `exec`, at `/exec`** (D3), declared by `ExecRealm`
   (`#[Realm(key: 'exec', routeBase: '/exec', central: true)]`). Its provider writes, in a `register` link and so before
   beam-ux's realm-dashboard boot link:
   - `beam.core.realms.classes` += `ExecRealm`;
   - `beam.core.realms.exec` = `{label: Board, home: exec.home, gate: {entitlement: exec.view}}`, host keys winning;
   - `beam.core.entitlements.keys` += `exec.view`. An unlisted key is an undefined Gate ability, which denies everyone.

   The gate is declared even though a central realm with none falls back to `os.operate`: without it, a board seat
   could never open the board. No file in beam-ux changed for the realm.
3. **An exec grant is a read seat.** `exec.view` is composed by `splicewire/laravel-beam-accounts`'
   `DefaultEntitlementResolver` from an `operator` grant (staff see the board) or an `exec` grant. The `exec` grant
   composes `exec.view` and nothing else: `SEAT_REALMS = ['exec']` keeps the uniform realm rule from also composing
   `ux.exec.author` and `ux.author`, which would have handed every board member the site-authoring chrome.
4. **The host mounts the console in one line,** `Route::beamRealmConsole('exec', ['auth', 'verified'], page:
   'exec/console')`, a beam-ux macro that lifted the 45-line operator mount (ticket 04). The board's nav seat is
   `board` at `/exec/board`, a section index beside the `/exec/dashboard` leaf, the operator realm's shape (ticket 20:
   a seat sharing the dashboard's href made beam-inertia land on the section, not the cards).

## Consequences

- A member holding neither grant gets 403 on `/exec`, `/exec/dashboard` and `/exec/frame/manifest`, and the realm is
  absent from their realm manifest, not hidden.
- Every future package realm gated on a new key must also append it to `beam.core.entitlements.keys`.
- Viewing the board as a synthetic principal (D7) is deferred: synthetic tenants only, time-boxed, never a real
  tenant.
