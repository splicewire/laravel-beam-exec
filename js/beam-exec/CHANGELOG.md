# @splicewire/beam-exec

## 0.1.1

### Patch Changes

- de1b6b7: The package installs from GitHub. 0.1.0's repository export-ignored `js/`, and codeload (which serves a public
  `github:` pin as a `git archive` tarball) honoured it, so the tarball had no `js/beam-exec` and no GitHub spec form could
  install it. `js/beam-exec` now ships in every archive. No source change.

## 0.1.0

### Minor Changes

- af41e2d: The exec board's lens bar: `BoardLensBar`, a Live / Truth / Scenario / Projection segmented switch over the
  declared `ReadBoardLens` and `SwitchBoardLens` operations of `splicewire/laravel-beam-exec`, and the undismissable
  banner its synthesized or projected figures call for (driven by the data's watermark, with no close control).
  `execPages(resolveBeamPage)` serves the `exec/console` page: the packaged `frame/console` with the bar above it.
  React is the only peer.
