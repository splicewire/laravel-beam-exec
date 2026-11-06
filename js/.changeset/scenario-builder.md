---
"@splicewire/beam-exec": minor
---

The exec board's scenario builder: `ScenarioPanel`, a Scenarios panel over the declared `ReadScenarios`, `PreviewScenario`,
`SaveScenario` and `DeleteScenario` operations of `splicewire/laravel-beam-exec` (list the board's scenarios, compose a
draft from the host's blueprints with a live revenue and MRR-trend preview, save it as `scenario:<id>`, delete a stored
one). `exec/console` now draws it under the lens bar, and the bar lists every scenario so a saved one can be chosen at
once (`BoardLensBar` takes `version` to read again). `execPages(resolve, { trendCard })` hands the preview a trend
widget such as `@schemastud/frame`'s `TrendCard`; without one the series is a table. React stays the only peer.
