# Changesets

Version custody for `@splicewire/beam-exec`, held in this private workspace. When you change the
package, run `pnpm changeset`, pick the bump level, and write a one-line summary. `pnpm version`
(at the workspace root) runs `changeset version` to compute the next version from the pending
changesets in this directory.

- Independent mode — the package versions on its own.
- `access: public` — matches the package's `publishConfig`.
- The first version, 0.1.0, is recorded in `beam-exec/CHANGELOG.md`; no changeset is pending for it.
- The source manifest's `version` stays below the release (0.0.0 before the first one). The release
  pipeline's version commit sets it (`pnpm release:plan --seed '@splicewire/beam-exec@<sha>=<version>'`),
  because `release:run` needs a `package.json` edit to make its version commit.
