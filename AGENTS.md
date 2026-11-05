> You are in **splicewire/laravel-beam-exec** — the exec realm for Beam hosts: a board-meeting dashboard read through one lens.

A Laravel package (`Splicewire\Beam\Exec\*`). It adds an `exec` realm at `/exec` whose cards show
growth, revenue, usage and posture, each read through a lens: Live, Truth, Scenario or Projection.
Beam tier, MIT.

## What is here now

- The service provider, `config/beam/exec.php` (merged under `beam.exec.*`; `cards`), and the
  testbench and Pest harness (which boots beam and beam-ux for real).
- The `exec` realm (`src/Realm/ExecRealm.php`, `src/Concerns/WiresExecRealm.php`): a `#[Realm]` marker
  in `beam.core.realms.classes`, the `beam.core.realms.exec` profile gated by `entitlement:exec.view`, the
  `exec.view` entitlement key, and the `board` nav seat. beam-ux gives it its dashboard. The host mounts
  the console with `Route::beamRealmConsole('exec', [...])` (README).

- The card DX (`src/Board/`, `src/Concerns/WiresBoardCards.php`): `#[BoardCard]` on a `BoardMetric`,
  the `beam.exec.cards` registry (`BoardCardRegistry`, filled from config), the `BoardSource` port
  (bound scoped through the lens, below), and `splicewire:beam:exec:make-card`. Each card is registered
  at boot as an imperative particle resource `exec-{key}` (realm `exec`, section `board`) with an
  INSTANCE backing, `BoardCardBacking`, carrying its key, and `BoardCardSummaryProvider`. Read Data:
  `BoardFigureData`. The reference card is `TenantsCard`.
- The built-in metric cards (`src/Board/Cards/`): `TenantsCard`, `RevenueCard`, `MrrTrendCard`, `GrowthCard`,
  `PlanMixCard`, `UsageCard`, `PostureCard` and `RiskCard`. `BoardSource` reads `asOf()`, `tenants()`,
  `subscriptions()`, `usage($month)`, `revenue($months)` (beam-commerce `PartyPeriodRevenue` rows; the card
  aggregates them with beam-commerce's pure `RevenueMovements`, never its own arithmetic) and `posture()`;
  the value objects are `BoardTenant`, `BoardSubscription` and `BoardUsage`, and `Figures` holds the
  shared rate arithmetic. Every read is narrowed to the tenant set, so a card never filters by lens.
- The posture registry (`src/Posture/`, `src/Concerns/WiresPosture.php`): `beam.exec.posture`
  (`PostureRegistry`, filled from config, key => `PostureContributor` class), read by
  `LiveBoardSource::posture()`. The default `doctor` contributor runs `beam.doctor.audits` through
  the doctor's runner (a gate failure's report is read, not thrown) off the board read: a read serves
  the last report with its `measuredAt` and queues `RefreshDoctorPosture` when it is missing or older
  than `beam.exec.doctor_posture_ttl` (cold = one unmeasured signal, never a pass; 0 = inline). Packages contribute DOWN with `afterResolving(PostureRegistry::class, …)`.

- The lens (`src/Board/Lens/`): `BoardLens` (Live, Truth, Scenario or Projection) is bound scoped, resolved by
  `BoardLensResolver` from the encrypted `beam_exec_lens` cookie (`live`, `truth`, `scenario`,
  `scenario:<portfolio>`, `projection` or `projection:<portfolio>`); an absent or unknown value reads the default, Truth while the reporting
  toggle excludes synthetic tenants and Live otherwise. The resolver builds the `BoardSource`:
  `LiveBoardSource::live()`/`truth()`, or `SyntheticBoardSource`, a beam-synthetic portfolio's roster
  read in memory over the months ending with the as-of month and priced by beam-commerce's `PlanPricer`
  over a price list (`ScenarioPlan`) read from the host's plans when the source is built. That plans
  read is the Scenario board's only query; every card read is in memory. Scenario posture is empty.
- The Projection lens (`src/Board/Sources/ProjectionBoardSource.php`, `src/Board/BoardProjection.php`): a
  decorator over the default reading. At build it makes one `revenue()` call on that reading (the anchor, the
  last closed month, and the 12 before it), takes the anchor's rows as beam-synthetic's `ProjectionBaseline`, and
  runs `ProjectionEngine` at `beam.exec.projection` (a portfolio's churn and expansion rates win where it states
  them). Every read after that is in memory; `asOf()` is the last projected month; tenants, subscriptions, usage
  and posture are refused. `BoardSource::projection()` says what is projected; `Figures::forecast()` marks a
  figure's month. Only cards with `#[BoardCard(projects: true)]` are measured under it.
- A card's own widget (`src/Board/BoardCardContextNodes.php`): a class-level `#[WidgetIn('summary', …)]` on a
  card class is contributed to its `exec-{key}` resource through frame's `ResourceContextContributor` port
  (decorated in `WiresBoardCards`). `MrrTrendCard` chooses `trend-card` this way.
- The watermarks (`src/Board/BoardWatermark.php`): `BoardCardRegistry::figures()` measures a
  card and, when the source's `synthesizedFrom()` is non-null, flags every figure `synthesized` and
  leads with a `synthesized` figure ("Synthesized — not real data", the scenario's name, tone warn);
  when its `projection()` is non-null, flags every figure `projected` and leads with a `projected`
  figure ("Projected — not real data", what was projected, tone warn).
  The card's page and its dashboard summary both read through `figures()`.

- The lens bar (`src/Board/Lens/BoardLensBar.php`, `src/Ops/`, `src/Concerns/WiresBoardLens.php`, `js/beam-exec`):
  two declared `#[ParticleOp]`s on `exec-dashboard`, `ReadBoardLens` (`GET {base}/lens`) and `SwitchBoardLens`
  (`POST {base}/switch-lens`, sets the cookie, refuses an unhonoured lens with a 422), both `exec.view` on the
  entitlement plane and answering `BoardLensBarData`. The banner is built from the source's watermark, never the
  cookie. `NoStoreWatermarkedResponses` sends `no-store, private` on every response the registry marked. The JS
  package `@splicewire/beam-exec` draws the switch and the banner (`exec/console` page, `BoardLensBar`).

## Rules that are easy to get backwards

- **Tiering.** This package requires beam, beam-ux, beam-tenancy, beam-commerce and beam-synthetic
  DOWN. It must never require `splicewire/tower`, `splicewire/laravel-satellite` or any
  `splicewire/laravel-tower-*`. Tower plugs into exec's registries; exec never names tower. The
  fences are declared in `composer.json` `extra.package-topology` and pinned by `tests/Topology/`.
- **Synthetic is a hard require** (owner ruling D9), because the Scenario and Projection lenses read
  a synthetic portfolio.
- **Cards read time only through `BoardSource::asOf()`.** "This month" is the source's month, never
  `now()`: a scenario or projection reads a month that is not today's.
- **Cards read only through `BoardSource`.** Under the Scenario and Projection lenses every card must run
  with zero queries once the source is built. `tests/Board/BoardCardsTest.php` measures every registered card against an in-memory source
  inside `DB::listen`; a new card that queries turns it red. A new `BoardSource` method must be
  answerable in memory.
- **A card resource is imperative on purpose.** The backing is an instance carrying the card key; the
  attribute slot is a class-string (particle doctrine). Do not "fix" it into `#[ParticleResource]`.
- **The watermarks are not configurable.** Any synthesized or projected figure carries one. They are
  stamped in `BoardCardRegistry::figures()`, never by a card; measure a card through `figures()`, not
  `metric()->measure()`, anywhere its figures reach a reader. The figure ids `synthesized` and
  `projected` are reserved. A card sets only `forecast`, per month, with `Figures::forecast()`.
- **The banner has no off switch.** No field hides it and the renderer draws no close control. Its presence
  follows the source's watermark, so do not key it on the cookie or the mode.
- **`projects: true` is a promise to read revenue only.** The Projection source refuses every other read,
  so a card that declares it and reads tenants fails loudly. Leave it off and the card is absent under
  Projection.
- **The provider never hand-lists steps.** A concern is a `Wires*` trait whose methods carry
  `#[Chained('register')]` or `#[Chained('boot')]` with a declared `order:`. The realm marker is a
  `register` link, because beam-ux's realm-dashboard boot link reads the realm list. `ScopesRegistrant`
  records this package as the registrant of every registry write in both phases.
- **Config.** A key lands in the same commit as the code that reads it.

## Vendored family-package conventions

Before editing through `vendor/` into another family package, read that package's own `AGENTS.md`.

## Testing

`composer test` (Pest on Testbench). Install through the local overlay: `composer.local.json` is
generated by the ecosystem's `pnpm repos` and stays gitignored.
