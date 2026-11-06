# ADR-0003 — Posture is a registry of contributors, and the doctor report is refreshed off the request

**Status:** accepted
**Date:** 2026-11-05
**Repo:** `splicewire/laravel-beam-exec`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/exec-board/` (PLAN.md D10; tickets 09, 10, 16, 20b).
**Extends:** ADR-0002 (posture is read through `BoardSource::posture()`).
**Relates to:** `docs/registries/beam.exec.posture.mdx`; `splicewire/laravel-beam` `src/Doctor/BeamDoctorManifest.php`
(the manifest the default contributor runs).

## Context

A board should see the estate's compliance and readiness posture next to its money. Beam already has a doctor:
`rushing/laravel-doctor` findings over beam's `beam.doctor.audits` manifest, with pass/warn/fail and a
"measured nothing" flag (`Finding::$conclusive`). Other packages hold posture of their own (tower's determination
rollup). Exec may not require tower. And on a fresh starter host a full doctor run took 34 s measured alone and 65 s
under a loaded `artisan serve`; nine source-scanning audits accounted for about 31.7 s of the 33.7 s
(`exec-board/evidence/20b/doctor-audit-times.txt`).

## Decision

1. **`beam.exec.posture` is a popcorn registry of `PostureContributor`s** (`#[IsRegistry]`, keyed, kebab-case keys,
   Reject on duplicates, Optional), config-filled. The default is `['doctor' => DoctorPostureContributor::class]`.
   A package contributes from its own side with `afterResolving(PostureRegistry::class, …)`, so exec never names it.
   Tower does exactly this (`DeterminationPostureContributor`, key `determination`, declared only when exec is).
2. **Signals speak the doctor's vocabulary.** `PostureSignal(check, DoctorStatus, detail, conclusive, source,
   measuredAt)` with `fromFinding()`; no new status enum. An unmeasured signal is never counted as passing.
3. **A contributor that throws becomes one failing `contributor-errored` signal** stamped with its key; the others
   still report.
4. **The board never runs the audits on a read** (ticket 20b). The doctor contributor serves the last stored report
   (`beam-exec:posture:doctor-report`, kept until replaced), and each signal carries when it was measured; the posture
   card shows the report's age as **Measured**.
   - With no report yet, a read returns one `doctor.unmeasured` signal (a pass with `conclusive: false`), so the card
     reads **Unmeasured**, never Pass, and a refresh is queued.
   - With a report older than `beam.exec.doctor_posture_ttl` (900 s), the report is still served and a refresh is
     queued. At most one refresh is outstanding per ttl, and a job that finds a fresh report runs nothing.
   - `splicewire:beam:exec:refresh-posture` refreshes now, for a schedule or a deploy step. A ttl of 0 runs the audits
     inline on every read, for a host that chooses to wait.
5. **The cards count what is wrong now.** Posture: status, passing, warnings, failing, unmeasured, measured. Risk:
   suspended tenants + failed provisioning + failing checks, with no weighting or forecast. When no posture signal is
   conclusive, risk's failing checks read **Unmeasured** and its headline loses the calm tone, so a cold board never
   shows "0 failing checks" in green.
6. **A Scenario lens has no posture** (ADR-0004): a scenario models customers and money, not the estate.

Rejected: restricting the board to the cheap audits (the slow ones are the substantive ones: house style, central
pins, registry shapes) and a time budget (one audit alone takes 11.5 s, so a budget either bounds nothing or drops
the same audits, depending on load).

## Consequences

- A host with no queue worker and no schedule keeps reading Unmeasured; the README tells hosts to schedule the
  refresh command. The commerce starter does.
- The doctor manifest now has a request-facing reader. Beam's manifest docblock records it, and why `Gated` is still
  absent: the board is the door, and the audits that run are the same for every reader.
