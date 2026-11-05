# splicewire/laravel-beam-exec

The exec realm for Beam hosts: a board-meeting dashboard at `/exec`. Its cards will show growth,
revenue, usage and posture, each read through one lens. The lens can be live data, real tenants
only, a synthetic scenario, or a projection forward from today.

This release adds the `exec` realm, its gate and its dashboard, and the board's cards: a way to
declare one (`#[BoardCard]` on a `BoardMetric`), the `beam.exec.cards` registry, the `make-card`
generator, and one built-in card, **Tenants**. The growth, revenue, usage, plan-mix, posture and risk
cards, and the lens, follow.

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

## Cards

Each card is a class that measures a few figures through a `BoardSource`:

```php
#[BoardCard(key: 'pipeline', label: 'Pipeline', icon: 'Funnel', order: 20)]
final class PipelineCard implements BoardMetric
{
    public function measure(BoardSource $source): array
    {
        return [new BoardFigureData(id: 'total', label: 'Tenants', value: count($source->tenants()))];
    }
}
```

List it in `config('beam.exec.cards')` (a list you declare replaces the default, so keep
`TenantsCard::class` in it if you want it), or add it from your provider's `register()` with
`app(BoardCardRegistry::class)->add(PipelineCard::class)`. `php artisan splicewire:beam:exec:make-card
PipelineCard` generates one.

Each card becomes a read-only resource, `exec-{key}`, in the `exec` realm, so it shows up three ways
without more wiring: as a card on `/exec/dashboard`, as a leaf in the Board section, and as its own page
at `/exec/exec-{key}` listing the same figures. A card reads under the board's gate unless its
attribute names a narrower `ability`.

A card reads **only** through the source it is handed. The package binds `LiveBoardSource`, which
reads the live estate centrally, synthetic tenants included; the lens will swap it. The full guide is
the registry page, [`docs/registries/beam.exec.cards.mdx`](docs/registries/beam.exec.cards.mdx).

The built-in cards are **Tenants** (headcount), **Posture** (the worst status any posture check
reports, and how many pass, warn, fail or measured nothing) and **Risk** (suspended tenants, failed
provisioning and failing checks, summed).

## Posture

The posture and risk cards count signals from the posture contributors in `beam.exec.posture`. The
default, `doctor`, runs the readiness audits registered for `splicewire:beam:doctor` and reports each
finding as one signal, cached for `beam.exec.doctor_posture_ttl` seconds (900). A package adds its own
contributor from its provider, naming the registry only by its class string:

```php
$this->app->afterResolving(PostureRegistry::class, fn (PostureRegistry $posture) => $posture->register('ledger', LedgerPosture::class));
```

The guide is [`docs/registries/beam.exec.posture.mdx`](docs/registries/beam.exec.posture.mdx).

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

This package declares two registries:

- [`beam.exec.cards`](docs/registries/beam.exec.cards.mdx): the board's cards, `key => BoardMetric`
  class, filled from `config('beam.exec.cards')`. Duplicate keys are rejected.
- [`beam.exec.posture`](docs/registries/beam.exec.posture.mdx): the posture contributors,
  `key => PostureContributor` class, filled from `config('beam.exec.posture')` and from other packages'
  providers. Duplicate keys are rejected.

It registers into:

- `beam.core.realms.classes` (config): `Splicewire\Beam\Exec\Realm\ExecRealm`, the `#[Realm]` marker,
  which beam registers onto `beam.realm`;
- `beam.core.entitlements.keys` (config): `exec.view`, so beam defines the `entitlement:exec.view` ability;
- `beam.nav.sections`: the `board` seat in the `exec` realm, gated on `exec.view`;
- `beam.particle.resources`: one `exec-{key}` resource per card, in the `exec` realm and the `board`
  section.

beam-ux registers the realm's dashboard (`exec-dashboard`) into `beam.particle.resources` itself, as it
does for every realm.

## Testing

Run `composer test`.
