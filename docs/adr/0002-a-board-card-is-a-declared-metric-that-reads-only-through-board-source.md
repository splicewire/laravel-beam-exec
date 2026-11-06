# ADR-0002 — A board card is a declared metric that reads only through `BoardSource`

**Status:** accepted
**Date:** 2026-11-05
**Repo:** `splicewire/laravel-beam-exec`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/exec-board/` (tickets 05, 08, 12, 19, 20b).
**Extends:** ADR-0001 (the realm the cards are seated in).
**Relates to:** `splicewire/laravel-beam docs/adr/0222-a-realm-dashboard-is-one-read-only-resource-per-realm-of-card-rows.md`,
`schemastud/laravel-frame docs/adr/0003-summary-and-overview-are-collection-render-contexts-and-a-summary-provider-is-a-hidden-resource-slot.md`;
`docs/registries/beam.exec.cards.mdx`.

## Context

The board has eight figures-bearing cards today (tenants, revenue, MRR trend, growth, plan mix, usage, posture, risk)
and must take a host's own. Every card must also draw under four lenses (ADR-0004), one of which is a portfolio held
in memory with no database behind it. A frame summary provider is handed only the `ResourceDefinition`, and the
summary route forwards no facets, so a card cannot be told its lens through a parameter.

## Decision

1. **A card is a class implementing `BoardMetric::measure(BoardSource): list<BoardFigureData>`, declared with
   `#[BoardCard(key, label, icon, order, ability, projects)]`.** `beam.exec.cards` lists the classes; the key lives on
   the attribute, and the registry refuses a class whose attribute is missing or whose key is taken.
   `splicewire:beam:exec:make-card` mints one into `App\Board\Cards`.
2. **`BoardCardRegistry` is a popcorn `#[IsRegistry(root: 'beam.exec.cards')]`** (Reject on duplicates, Optional),
   filled from config under `config:beam.exec.cards` and then from code. A host's list replaces the default.
3. **Each card is its own framed resource, `exec-{key}`,** registered imperatively (one backing class carries each
   key) in realm `exec`, section `board`, read-only, with `BoardCardSummaryProvider`. The prefix exists because a
   resource key is global (`tenants` is the operator list's). A card's page and its dashboard summary read the same
   `BoardCardRegistry::figures()` path, so they cannot disagree. A card may narrow its gate with `ability:`; without
   one it reads under `entitlement:exec.view`, so no card is a softer door than the board.
4. **The wire is the declared `BoardFigureData`** (particle doctrine site 1), mapped one for one onto frame's
   `SummaryFigureData`. It declares `#[Column]`s (Figure, Value, Forecast) so a card's own page draws cells (ticket
   20b), and an optional `forecast` that the trend widget reads (ADR-0004).
5. **`BoardSource` is the only thing a card reads,** a request-scoped container binding. Its surface grows with the
   metrics that need it (`lens()`, `synthesizedFrom()`, `projection()`, `asOf()`, `tenants()`, `subscriptions()`,
   `usage()`, `revenue()`, `posture()`), and **every method must be answerable in memory**. Its values are plain
   (`BoardTenant`, `BoardSubscription`, `BoardUsage`, commerce's `PartyPeriodRevenue`), so a card cannot reach back into
   the database through them. "This month" is `asOf()`, never the wall clock. Every read is already narrowed to the
   tenants `tenants()` returns; a card never filters by lens.
6. **A conformance test measures every registered card against an in-memory source inside `DB::listen` and requires
   zero queries,** with a control card that queries once. It is the Scenario lens's guarantee in miniature.
7. **The revenue figures come from beam-commerce, not from the board.** `RevenueCard` and `MrrTrendCard` read
   `BoardSource::revenue()`, which the live source answers with commerce's `MeasuredRevenueLedger` and every source
   aggregates through commerce's pure `RevenueMovements`
   (`splicewire/laravel-beam-commerce docs/adr/0008-mrr-is-contracted-recurring-revenue-derived-by-one-aggregator.md`).
   Usage on the board is provider cost; usage revenue is a revenue figure.

## Consequences

- A host adds a card by writing one class and one config line; it appears as a dashboard card, a Board leaf and its
  own page, for `exec.view` holders only.
- A new `BoardSource` method is a cost paid by every source, including the in-memory ones. A metric that cannot be
  answered without a query does not belong on the board until a source can answer it.
- D5's per-lens, per-month cache is not built: commerce's batched and shared measures brought a 24-month read to 9
  queries, flat in parties and months.
