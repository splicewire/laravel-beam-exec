# Beam Exec

The exec realm and its board dashboard. The terms below are the ones this package owns.

## Language

**Exec realm**:
The central realm at `/exec`, gated by `entitlement:exec.view`. Who holds the entitlement is a
composition rule in beam-accounts, not a role this package mints.

**Board card**:
One dashboard card, declared with `#[BoardCard]` and backed by a `BoardMetric`. A card reads only
through the lens's `BoardSource`. Each card is drawn as its own read-only resource, `exec-{key}`.
_Avoid_: widget (the frame JS card set's term for the rendered piece)

**Board figure**:
One keyed, labelled value a card measures (`BoardFigureData`). The same list is the card's rows on
its own page and its figures on the dashboard, so the two cannot disagree.

**Board source**:
`BoardSource`, everything a card may read. Request-scoped; the lens chooses which one is bound.
Every method must be answerable in memory, because a scenario is one.

**As-of instant**:
`BoardSource::asOf()`, the moment a board reads as of. Every "this month" on a card is this instant's
month. The live source answers the wall clock; a scenario or projection answers its own month.

**Growth**:
The tenant base at the as-of instant against the base the month began with (and the base a year
earlier). A tenant counts in a base once created; a tenant with no known creation date counts in every
base. Logos that leave belong to revenue, not growth.

**Plan mix**:
The tenants holding each plan in force at the as-of instant, counted by tenant, not by subscription.
A Brokered Tenant holds no plan of its own and is never "without a plan".

**Usage cost**:
What metered usage cost the platform (provider cost), by tenant and meter and month, before any
markup a plan charges on it. Revenue from usage is a separate figure.

**Lens**:
`BoardLens`, the per-request choice of what the board reads:
- **Live**, the default: the database as it is, synthetic tenants included;
- **Truth**: real tenants only, and the default once the reporting toggle is on;
- **Scenario**: a synthetic portfolio, read in memory: every card read issues zero queries (the
  plans it is priced by are read once, when its source is built). It has no posture;
- **Projection**: the default reading's revenue at the anchor month, held flat per party and run
  forward by beam-synthetic's projection engine. One revenue read when its source is built, then
  every card read in memory. It models revenue only: only cards that declare `projects` are drawn.
Chosen by the encrypted `beam_exec_lens` cookie; an absent or unknown value reads the default.

**Stored scenario**:
A scenario a board member saved with the **Scenario builder**: a beam-synthetic portfolio row in the central
`beam_synthetic_portfolios` table (`StoredPortfolios`), read by `PortfolioRegistry` after the portfolio files. A
declared portfolio (a file) always wins its id; a stored row is validated again whenever it is read. _Avoid_:
"custom portfolio", "user scenario".

**Scenario builder**:
The board's Scenarios panel and its four declared operations: list the scenarios and blueprints, preview a draft
(the Scenario lens over the draft, in memory), save it as a **Stored scenario**, delete one. Preview is for every
board reader; save and delete need `exec.scenario.manage`, which the board seat does not hold.

**Scenario price list**:
The host's plans a Scenario is priced by (`ScenarioPlan`: slug, name, billing components), copied
from the plan catalog when the scenario's source is built, so pricing a scenario month reads no row.

**Synthesized watermark**:
The sticky bar and per-card note, "Synthesized — not real data", on every figure a Scenario lens
produces. It cannot be configured off. In the data it is a `synthesized` flag on every
figure and a leading `synthesized` figure on every card (`BoardWatermark`), stamped by the card
registry, never by a card. The sticky bar is the lens bar's banner.

**Lens bar**:
The board's segmented switch over the four lenses, its list of every scenario (`scenario:<id>`), and the banner
that states the watermark for the page under Scenario or Projection. Read and switched through two declared operations
(`ReadBoardLens`, `SwitchBoardLens`); the banner is built from the source's watermark, not the
cookie, and cannot be dismissed. Every response that carried a watermarked figure is no-store.

**Projected watermark**:
The Projection lens's mark, "Projected — not real data", kept distinct from the synthesized one: a
projection starts from real revenue, but it is not a measurement. A `projected` flag on every figure
and a leading `projected` figure saying what was projected, stamped the same way.

**Anchor month**:
The last month a projection reads rather than projects: the last closed month before the as-of
instant. Its rows are the **baseline**.

**Forecast**:
A figure that draws a projected month (`forecast: true`), as opposed to one that draws a measured
month under a projection (`false`). Set by the card, per month; it is the one projection flag frame's
summary figure carries, so a series widget can dash the forecast.
_Avoid_: prediction, estimate (a projection holds the anchor flat and applies stated rates; it fits
nothing)

**Posture**:
How the estate stands, as a list of **posture signals** (`PostureSignal`: a check, pass / warn /
fail in the doctor's vocabulary, a detail, and whether it measured anything). Gathered from the
contributors in the `beam.exec.posture` registry and read by cards only through
`BoardSource::posture()`. The default contributor is doctor-backed; higher tiers such as tower
contribute their own signals into it.
_Avoid_: health (a liveness word), score (posture is counted, never weighted)

**Risk**:
What needs attention now, counted from signals the board already has: suspended tenants, failed
provisioning, and failing posture checks. A count, not a forecast; warnings are not risks.

**MRR**:
Contracted recurring revenue: subscription fees plus per-seat and per-Brokered-Tenant fees over the
seats and children measured that month (beam-commerce owns the term and its arithmetic,
`RevenueMovements`). Usage revenue is a separate figure. Billed and paid amounts reconcile in the
Live lens only.
