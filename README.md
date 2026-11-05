# splicewire/laravel-beam-exec

The exec realm for Beam hosts: a board-meeting dashboard at `/exec`. Its cards will show growth,
revenue, usage and posture, each read through one lens. The lens can be live data, real tenants
only, a synthetic scenario, or a projection forward from today.

This release is the package scaffold: the service provider and the `beam.exec` config root. The
realm, the cards, the metrics and the lens follow.

## Install

Require `splicewire/laravel-beam-exec`. It requires `splicewire/laravel-beam`,
`splicewire/laravel-beam-ux`, `splicewire/laravel-beam-tenancy`, `splicewire/laravel-beam-commerce`
and `splicewire/laravel-beam-synthetic`. The provider is auto-discovered. To override config, publish
`config/beam/exec.php`:

```bash
php artisan vendor:publish --tag=beam-exec-config
```

## Registries

This package declares no registries yet, and registers into none.

## Testing

Run `composer test`.
