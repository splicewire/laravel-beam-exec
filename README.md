# splicewire/laravel-beam-exec

The exec realm for Beam hosts: a board-meeting dashboard at `/exec`. Its cards will show growth,
revenue, usage and posture, each read through one lens. The lens can be live data, real tenants
only, a synthetic scenario, or a projection forward from today.

This release adds the `exec` realm, its gate and its dashboard. The cards, the metrics and the lens
follow.

## Install

Require `splicewire/laravel-beam-exec`. It requires `splicewire/laravel-beam`,
`splicewire/laravel-beam-ux`, `splicewire/laravel-beam-tenancy`, `splicewire/laravel-beam-commerce`
and `splicewire/laravel-beam-synthetic`. The provider is auto-discovered. To override config, publish
`config/beam/exec.php`:

```bash
php artisan vendor:publish --tag=beam-exec-config
```

## Mount the board

The package registers the realm. The host mounts its console with one line in `routes/web.php`, above
`Route::beamUxSite()` (whose catch-all would otherwise take `/exec`):

```php
Route::beamRealmConsole('exec', ['auth', 'verified']);
```

That line is `splicewire/laravel-beam-ux`'s realm-console macro. It mounts three routes, each behind
your middleware plus `can:entitlement:exec.view`:

| route | path | what |
|---|---|---|
| `exec.home` | `/exec` | redirects to the dashboard |
| `exec.frame.manifest` | `/exec/frame/manifest` | the exec realm's frame manifest |
| `exec.frame.console` | `/exec/{frameRoute}` | the frame console, for every leaf the realm projects |

## Who sees the board

The realm is gated by `entitlement:exec.view`. With `splicewire/laravel-beam-accounts`' default resolver,
two grants compose it:

- an `operator` realm grant: anyone who operates the platform sees its board;
- an `exec` realm grant, the board seat: it composes `exec.view` and nothing else, so a board member
  can read the board without becoming a site author.

A member without either gets a 403 at `/exec`, and the realm is left out of their realm manifest.

## Configuration

The realm profile is written for you in `beam.core.realms.exec`:

```php
['label' => 'Board', 'home' => 'exec.home', 'surface' => 'app',
 'gate' => ['entitlement' => 'exec.view', 'mode' => 'hard']]
```

Declare `beam.core.realms.exec` in your own `config/beam/core.php` to change any of those keys; yours win
key by key.

## Registries

This package declares no registries yet. It registers into:

- `beam.core.realms.classes` (config): `Splicewire\Beam\Exec\Realm\ExecRealm`, the `#[Realm]` marker,
  which beam registers onto `beam.realm`;
- `beam.core.entitlements.keys` (config): `exec.view`, so beam defines the `entitlement:exec.view` ability;
- `beam.nav.sections`: the `board` seat in the `exec` realm, gated on `exec.view`.

beam-ux registers the realm's dashboard (`exec-dashboard`) into `beam.particle.resources` itself, as it
does for every realm.

## Testing

Run `composer test`.
