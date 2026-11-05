# splicewire/laravel-beam-exec

The exec realm for Beam hosts: a board-meeting dashboard at `/exec`. Its cards will show growth,
revenue, usage and posture, each read through one lens. The lens can be live data, real tenants
only, a synthetic scenario, or a projection forward from today.

This release adds the `exec` realm, its gate and its dashboard, and the board's cards: a way to
declare one (`#[BoardCard]` on a `BoardMetric`), the `beam.exec.cards` registry, the `make-card`
generator, the built-in cards **Tenants**, **Revenue**, **MRR trend**, **Growth**, **Plan mix**, **Usage**,
**Posture** and **Risk**, and the board's lens: **Live**, **Truth**, a synthetic **Scenario** or a revenue
**Projection**. The lens bar follows.

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
the built-in `TenantsCard`, `RevenueCard`, `GrowthCard`, `PlanMixCard` and `UsageCard` in it if you want them), or add it
from your provider's `register()` with
`app(BoardCardRegistry::class)->add(PipelineCard::class)`. `php artisan splicewire:beam:exec:make-card
PipelineCard` generates one.

Each card becomes a read-only resource, `exec-{key}`, in the `exec` realm, so it shows up three ways
without more wiring: as a card on `/exec/dashboard`, as a leaf in the Board section, and as its own page
at `/exec/exec-{key}` listing the same figures. A card reads under the board's gate unless its
attribute names a narrower `ability`.

A card reads **only** through the source it is handed: the tenants, their plan subscriptions, their
monthly metered usage and their monthly revenue rows (beam-commerce's `PartyPeriodRevenue`), as of the source's own instant (`asOf()`). Which source that is, is the board's lens (below). Every
subscription, usage and revenue row is narrowed to the tenants the source sees.

The **Revenue** card (`exec-revenue`) reads thirteen months through beam-commerce's
`MeasuredRevenueLedger` and turns them into MRR, ARR, this month's movements, logo churn and
twelve-month net revenue retention with `RevenueMovements`. MRR is contracted recurring revenue;
usage revenue is its own figure. The live read is linear in tenants × months (beam-commerce's README
gives the measured cost), so a large book will want it cached. The full guide is
the registry page, [`docs/registries/beam.exec.cards.mdx`](docs/registries/beam.exec.cards.mdx).

The **MRR trend** card (`exec-mrr-trend`) is the same thirteen months as a line: one figure per month, its
value the month's MRR as a plain number. It chooses `@schemastud/frame`'s `trend-card` widget with
`#[WidgetIn('summary', 'trend-card')]` on its class, and under the Projection lens the projected months are
drawn dashed. The widget ships in `@schemastud/frame` 0.3.0; on an older frame the card shows frame's
"unbound widget" marker instead.

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

## The lens

Each request reads the board through one lens, chosen by the `beam_exec_lens` cookie:

| cookie | lens | what the cards read |
|---|---|---|
| `live` | Live | the central tables, synthetic tenants included |
| `truth` | Truth | the central tables, real tenants only |
| `scenario`, `scenario:<portfolio>` | Scenario | a beam-synthetic portfolio, in memory |
| `projection`, `projection:<portfolio>` | Projection | the default reading's revenue, run forward in memory |

Without a cookie, or with one the board does not recognise (an unknown mode, a portfolio the host does not
declare), the board reads its default: **Truth** while beam-tenancy's reporting toggle excludes synthetic
tenants, the same switch `Tenant::reportable()` obeys, and **Live** otherwise. The toggle sets the default; it
does not stop a board member choosing Live.

The cookie is encrypted by your `EncryptCookies` middleware, like any cookie on a `web` route, so a value the
host did not encrypt is ignored. `BoardLensResolver::cookie($lens)` issues one (HTTP-only, same-site lax, eight
hours) and `forget()` clears it.

**Scenario** reads a portfolio from `beam.synthetic.portfolios`: the one the cookie names, or
`beam.exec.scenario_portfolio` (null = the first one declared; beam-synthetic ships `demo-mixed-book`). Its
members' curves are laid out so the portfolio's window ends with this month, each member starting at its
offset, the way `synthetic:materialize` writes the same portfolio's history. Revenue is priced by your own plans:
the scenario reads the plans its blueprints name once, when the request first asks for the board, and prices
every month through beam-commerce's `PlanPricer`, so its MRR moves through the same `RevenueMovements` the live
ledger feeds. That plans read is the only query a Scenario board makes; every card is drawn in memory. A
blueprint whose plan you do not have makes the revenue card fail, naming the plan, rather than price it at zero.
A scenario has no posture: the posture card reads "Unmeasured".

Every figure under a Scenario is **watermarked**, and the watermark cannot be turned off. Each figure carries
`synthesized: true`, and each card leads with a `synthesized` figure, "Synthesized — not real data", whose value
is the scenario's name. The watermark is in the data, so a renderer that knows nothing of lenses still prints it
on every card.

**Projection** takes the board's default reading (Truth while the toggle excludes synthetic tenants, Live
otherwise), reads its revenue for the last closed month and the twelve before it, once, and hands the last closed
month to beam-synthetic's projection engine as the baseline: each party's month held flat, then run forward at
`beam.exec.projection`'s rates for `horizon_months` (6) months. Every card read after that one revenue read is in
memory. "This month" becomes the last projected month, so the Revenue card describes where the book lands and the
MRR trend card draws the measured months up to the anchor and the projected ones after it.

```php
// config/beam/exec.php
'projection' => [
    'horizon_months' => 12,
    'churn_rate' => 0.02,          // monthly; parties leave whole
    'expansion_rate' => 0.01,
    'growth_rate' => 0.03,         // new paying parties per paying party per month
    'price_changes' => [['period' => '2027-01', 'rate' => 0.10, 'plan' => 'starter']],
],
```

A cookie `projection:<portfolio>` (or `beam.exec.projection.portfolio`) takes the churn and expansion rates from a
beam-synthetic portfolio where it states them. A price change at or before the anchor is skipped, because the
anchor's prices already include it.

A projection models revenue and nothing else, so only the cards that declare `projects: true` on their
`#[BoardCard]` (Revenue and MRR trend) are drawn; every other card is absent under it. Each figure for a projected
month carries `forecast: true`, and each figure for a measured month `forecast: false`; the flag reaches the
dashboard's summary figures, where `trend-card` dashes the forecast. Every figure is also **watermarked as
projected**: `projected: true`, and a leading `projected` figure, "Projected — not real data", whose value says
what was projected ("Live, October 2026 held flat, 6 months ahead"). A projection starts from real revenue, so it
is not marked synthesized, and it is never unmarked either.

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

The lens's default portfolio is `beam.exec.scenario_portfolio`, and the Projection lens's horizon and rates are
`beam.exec.projection` (both above).

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
