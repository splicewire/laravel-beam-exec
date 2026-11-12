---
"@splicewire/beam-exec": minor
---

The lens bar draws no band (owner, 2026-11-12; ADR-0004 clause 5, amended): under Scenario or Projection the page is
marked by the checked lens, the scenario's name in the picker, and each card's leading watermark figure. The server
still sends `banner`, and `LensBanner` stays exported for a host that wants one. The bar's scenario picker and the
Scenarios panel's blueprint picker are now the `@schemastud/ui` select. **New peer: `@schemastud/ui` `^0.2.1`**,
beside React; a host must provide it (the flagship already does).
