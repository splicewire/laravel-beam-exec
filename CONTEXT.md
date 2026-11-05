# Beam Exec

The exec realm and its board dashboard. The terms below are the ones this package owns; the lens
terms gain their code in a later exec-board ticket.

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

**Lens**:
`BoardLens`, the per-request choice of what the board reads:
- **Live**, the default: the database as it is, synthetic tenants included;
- **Truth**: real tenants only, and the default once the reporting toggle is on;
- **Scenario**: a synthetic portfolio, read in memory with zero queries;
- **Projection**: a live baseline at an anchor month, carried forward.

**Synthesized watermark**:
The sticky bar and per-card note, "Synthesized — not real data", on every figure a Scenario or
Projection lens produces. It cannot be configured off.

**Posture**:
The board's risk read, gathered through the `beam.exec.posture` registry. The default contributor is
doctor-backed; higher tiers such as tower contribute their own signals into it.

**MRR**:
Contracted recurring revenue: subscription fees plus per-seat fees over measured seats. Usage revenue
is a separate figure. Billed and paid amounts reconcile in the Live lens only.
