# ADR-0004 — A lens chooses the board's source, and a synthesized or projected figure is always watermarked

**Status:** accepted; clause 5 amended 2026-11-12 (the band)
**Date:** 2026-11-05
**Repo:** `splicewire/laravel-beam-exec`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/exec-board/` (PLAN.md D6, D12, D13; tickets 08, 16, 17, 19).
**Extends:** ADR-0002 (cards read only through `BoardSource`).
**Relates to:** `splicewire/laravel-beam-tenancy docs/adr/0005-whether-reporting-counts-synthetic-tenants-is-a-runtime-toggle.md`
(the toggle that sets the default lens), `splicewire/laravel-beam-synthetic docs/adr/0001-the-curve-kernel-is-neutral-and-blueprints-are-registered-from-host-directories.md`
and `docs/adr/0003-the-projection-engine-is-pure-and-runs-a-measured-baseline-forward.md` (what Scenario and Projection
read).

## Context

A host's book mixes real tenants with materialized synthetic ones (demos, a production roster that must look
believable). A board must be able to show the whole book, only the real one, a scenario that was never materialized,
and a forecast, and must never let a synthesized or forecast number pass as measured. Cards cannot be told which
reading they are in (ADR-0002), and the preview "view as" pattern already showed how a validated cookie, `no-store`
and a server-built bar payload can choose a reading per request.

## Decision

1. **Four lenses (D6), chosen per request by the encrypted `beam_exec_lens` cookie** (the host's `EncryptCookies`;
   an unencrypted value is dropped). `BoardLensResolver` binds the matching `BoardSource`:
   - **Live** (`live`): `LiveBoardSource`, central reads that include materialized synthetic tenants.
   - **Truth** (`truth`): the same source narrowed to real tenants (`notSynthetic()`).
   - **Scenario** (`scenario`, `scenario:<portfolio>`): `SyntheticBoardSource`, a beam-synthetic portfolio held in
     memory. Building it reads the host's `plans` once, to price the roster; every card read after that issues zero
     queries. An unpriced plan makes `revenue()` throw, naming the slug. Its posture is empty, and reads Unmeasured.
   - **Projection** (`projection`, `projection:<portfolio>`): `ProjectionBoardSource` decorates the default reading. It
     reads that reading's revenue once, for the last closed month and the 12 before it, runs beam-synthetic's
     projection engine forward `beam.exec.projection.horizon_months` (6), and answers in memory after that. Only
     cards declaring `#[BoardCard(projects: true)]` (Revenue, MRR trend) draw; the others are absent, and the source
     refuses their reads loudly rather than date today's facts in the future.
2. **The cookie vocabulary is closed, and a stale cookie reads the default.** Anything else (an unknown mode, a
   portfolio the host does not declare) never errors. **The default is Truth while beam-tenancy's reporting toggle
   excludes synthetic tenants, otherwise Live.** The toggle sets the default; it does not forbid Live.
3. **The switch is stricter than the cookie.** Two declared particle ops on `exec-dashboard`, `ReadBoardLens`
   (`GET {base}/lens`) and `SwitchBoardLens` (`POST {base}/switch-lens`, input `BoardLensChoiceData`), both
   `ability: exec.view`. A choice the board would not honour is a 422, never a cookie the next request ignores.
4. **The watermark is in the data, mandatory and not configurable (D12).** `BoardCardRegistry::figures()`, the one
   path a card page and its summary both read, stamps every figure of a synthesizing source `synthesized: true` and
   leads the card with a `synthesized` figure, "Synthesized — not real data", tone warn, valued with the scenario's
   name. A projecting source stamps `projected: true` and leads with "Projected — not real data", valued with what was
   projected ("Live, October 2026 held flat, 6 months ahead"). The ids `synthesized` and `projected` are reserved.
   A renderer that knows nothing of lenses still prints the warning.
5. **The lens bar follows the data, and draws no band.** `BoardLensBar::banner()` asks the same questions as the
   watermark, so `BoardLensBarData::$banner` is set exactly when the figures are marked, and it has no dismissal
   field. The wire field stays; the packaged bar does not draw it. **Amended 2026-11-12 (owner, owner-review-20261112
   ticket 04):** the sticky band is removed for both lenses that drew one, Scenario and Projection, superseding this
   clause's band and the "sticky bar" half of exec-board D12. Nothing replaces it: what marks the page is the checked
   lens, the scenario's name in the picker, and clause 4's leading figure on each card. Accepted consequence: a card
   page that measured nothing under a synthetic lens returns no figure, so it carries no mark. `LensBanner` stays
   exported, unused by the bar, for a host that wants a band. Clause 4 is unchanged. A
   watermarked response is sent `Cache-Control: no-store, private` by a `RequestHandled` listener, because card pages
   and summaries are served by routes this package does not mount.
6. **The bar is a JS package in this repo, `@splicewire/beam-exec`** (`js/beam-exec`). A host spreads
   `execPages(resolveBeamPage)` into its Inertia pages and names `page: 'exec/console'` on the realm console. Its
   peers are React and, since 2026-11-12 (owner-review-20261112 ticket 04), `@schemastud/ui`, whose select draws the
   scenario picker and the builder's blueprint picker. `js/beam-exec` ships in every archive (ticket 20b), so a `github:…&path:/js/beam-exec` pin
   installs.
7. **A forecast is a figure flag, not a new wire.** `BoardFigureData::$forecast` maps onto frame's optional
   `SummaryFigureData::$forecast` (absent unless sent, so every other summary keeps its bytes). The MRR trend card
   opts into frame's `trend-card` widget with `#[WidgetIn('summary', 'trend-card')]`, which draws forecast periods as
   a dashed segment starting on the anchor month.

## Consequences

- One switch in front of every card: a host's own cards inherit all four lenses and the watermark by reading
  `BoardSource`.
- Projection models revenue only. Projecting tenants and plan mix from the projected parties is nominated, not built.
- A Scenario build reads `plans`; a config-declared price list would make it query-free but could price a scenario
  with prices billing does not use, so it is nominated, not built.

Rule 11 (the 2026-11-12 amendment of clause 5 and the peer line of clause 6): grepped `undismissable` and
`no off switch` across .scratch — 11 hits in 5 files: exec-board `PLAN.md` (D12 is the operative one; its rule-11
line is written with the harness half of ticket 04), exec-board tickets 16, 17 and 20 (closed build records, left as
written), and owner-review-20261112 research 06 (the correction's source). In this repo the band was also described
by `README.md`, `CONTEXT.md`, `AGENTS.md` and `js/beam-exec/README.md`, corrected in the same change.
