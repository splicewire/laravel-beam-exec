import { defineConfig } from 'tsup';

export default defineConfig({
    entry: { index: 'src/index.ts' },
    format: ['esm'],
    dts: true,
    sourcemap: true,
    clean: true,
    // The host owns the one React (and its JSX runtime); never bundled into dist.
    external: ['react', 'react/jsx-runtime', 'react-dom'],
});
