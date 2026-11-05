# Exec JavaScript workspace

Run `pnpm install --frozen-lockfile`, `pnpm build`, `pnpm typecheck`, `pnpm lint:imports` and `pnpm test` from the repository root. The runtime package is at `js/beam-exec`; workspace tooling and its genuine lock live at Git root so normal Git dependency preparation selects pnpm. Direct leaf commands also discover this owning workspace.

React is the package's only peer; it has no first-party dependency. Keep the root esbuild override when updating the tooling policy. PHP Composer distributions exclude this JS workspace and its root tooling files.
