# splicewire/laravel-beam-exec

The exec realm for Beam hosts: a board-meeting dashboard at `/exec`. Its cards will show growth,
revenue, usage and posture, each read through one lens. The lens can be live data, real tenants
only, a synthetic scenario, or a projection forward from today.

This release adds the `exec` realm, its gate and its dashboard, and the board's cards: a way to
declare one (`#[BoardCard]` on a `BoardMetric`), the `beam.exec.cards` registry, the `make-card`
generator, the built-in cards **Tenants**, **Revenue**, **MRR trend**, **Growth**, **Plan mix**, **Usage**,
**Posture** and **Risk**, and the board's lens: **Live**, **Truth**, a synthetic **Scenario** or a revenue
**Projection**, with the lens bar that switches it.

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

To give the console the lens bar and the Scenarios panel, mount the lens and scenario operations in the same
group and name the bar's page on the console (both lines go above `Route::beamUxSite()`):

```php
use Splicewire\Beam\Exec\Ops\DeleteScenario;
use Splicewire\Beam\Exec\Ops\PreviewScenario;
use Splicewire\Beam\Exec\Ops\ReadBoardLens;
use Splicewire\Beam\Exec\Ops\ReadScenarios;
use Splicewire\Beam\Exec\Ops\SaveScenario;
use Splicewire\Beam\Exec\Ops\SwitchBoardLens;
use Splicewire\Beam\Facades\Particle;

Route::middleware(['auth', 'verified'])->group(function () {
    Particle::ops('exec', 'exec-dashboard', [
        ReadBoardLens::class, SwitchBoardLens::class,
        ReadScenarios::class, PreviewScenario::class, SaveScenario::class, DeleteScenario::class,
    ]);
});
Route::beamRealmConsole('exec', ['auth', 'verified'], page: 'exec/console');
```

A host that mounts only the two lens operations gets the bar without the builder: the panel then reads nothing
and says so.

and register the page from the `@splicewire/beam-exec` JS package (`js/beam-exec` in this repository) with your
Inertia pages:

```ts
import { beamInertiaOptions, resolveBeamPage } from '@splicewire/beam-inertia';
import { execPages } from '@splicewire/beam-exec';

beamInertiaOptions({ pages: { ...execPages(resolveBeamPage), ...pages } });
```

`exec/console` is the packaged frame console with the lens bar and the Scenarios panel above it. To draw the
scenario preview with the board's own `trend-card`, pass frame's widget:
`execPages(resolveBeamPage, { trendCard: TrendCard })` (`import { TrendCard } from '@schemastud/frame/cards'`). See "The
lens bar" and "Scenarios" below.

## Cards

Card pages declare facets through the optional `FacetedBoardMetric` contract: month on Revenue/Usage/Growth, measured or forecast on MRR trend, tone on Posture/Risk, and current plan/status on Tenants. Plan mix has no bar. Frame reads the declared vocabulary and passes `filter[...]` values to the card; the registry applies watermarks afterward, including an empty filtered synthetic page. See [card facets](docs/registries/beam.exec.cards.mdx#card-facets).

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
finding as one signal. A board read never runs those audits, which can take a minute: it serves the last
report with its age, and queues a refresh when there is none or it is older than
`beam.exec.doctor_posture_ttl` seconds (900). Until the first report lands, the posture and the risk card's
failing checks read Unmeasured. Run `php artisan splicewire:beam:exec:refresh-posture` on a schedule or after
a deploy to keep a report ready without a queue worker. A package adds its own
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
`beam.exec.scenario_portfolio` (null = the first one declared; beam-synthetic ships `demo-mixed-book`). That is a
portfolio file the host declares or a scenario stored with the builder (see "Scenarios" below); a stored one costs
the store's two reads (its table, its rows) once per request, on top of the plans read. Its
members' curves are laid out so the portfolio's window ends with this month, each member starting at its
offset, the way `synthetic:materialize` writes the same portfolio's history. Revenue is priced by your own plans:
the scenario reads the plans its blueprints name once, when the request first asks for the board, and prices
every month through beam-commerce's `PlanPricer`, so its MRR moves through the same `RevenueMovements` the live
ledger feeds. For a declared portfolio that plans read is the only query a Scenario board makes (a stored one adds
the store's two); every card is drawn in memory. A
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

## The lens bar

The bar is a segmented switch over the four lenses, a list of every scenario the board can show (choosing one
sets `scenario:<id>`, drawn with the `@schemastud/ui` select). It draws no band: under Scenario or Projection the page
is marked by the checked lens, the scenario's name in the picker, and the watermark figure that leads each card
(ADR-0004 clause 5, amended 2026-11-12). An unfiltered card that measured nothing under those lenses has no figure and so no mark; a filtered page retains its watermark even if no data figure matches.

Two declared operations on the `exec-dashboard` resource serve it, both subject-free and gated by `exec.view`:

| operation | route | what |
|---|---|---|
| `ReadBoardLens` | `GET /exec/lens` | the bar: the active lens, the four segments, the scenarios, and `banner`, the watermark's text for the page, which the packaged bar does not draw (`BoardLensBarData`) |
| `SwitchBoardLens` | `POST /exec/switch-lens` | takes `{"lens": "scenario"}` (`BoardLensChoiceData`), sets the encrypted `beam_exec_lens` cookie and answers the bar the next request will read |

The switch refuses, with a 422, any lens the board would not honour: a value outside the cookie vocabulary or a
portfolio this host does not declare. (A stale cookie still reads as the default lens; only choosing one is
strict.)

`banner` is taken from the source the cards read, the same `synthesizedFrom()` and `projection()` the
watermark is stamped from, so it is set exactly when the cards are watermarked, whatever the cookie says. A host that
wants a band can draw it with the package's `LensBanner`.

Rule 11 (the band, removed 2026-11-12): grepped `undismissable` and `no off switch` across .scratch — 11 hits in 5
files, listed in ADR-0004's rule-11 line.

Every response that carried a synthesized or projected figure is sent `Cache-Control: no-store, private`: a card's
page, a dashboard summary, the bar, and the switch itself. The card registry marks the request when it measures
a card from a watermarked source, and a `RequestHandled` listener sets the header, so the rule covers frame's and
beam's routes as well as this package's.

## Scenarios

A scenario is a beam-synthetic portfolio: blueprints, each with a count of tenants and a start offset in months,
plus optional monthly churn and expansion rates (which the Projection lens borrows; a scenario itself replays the
blueprints' curves as they are). The board can show any portfolio as `scenario:<id>`.

### How to add a portfolio

There are two ways, and both end as `scenario:<id>` in the lens bar.

**As a file, in your code.** Put a YAML or JSON file in a directory you list in `beam.synthetic.portfolio_paths`
(keep `DemoBlueprints::portfolios()` in the list to keep the Demo one):

```yaml
# resources/synthetic/portfolios/board-upside.yaml
id: board-upside
label: Board upside
members:
  - blueprint: demo-quillmere-studio
    count: 6
  - blueprint: demo-orrowby-hart
    count: 2
    startOffsetMonths: 4
churnRate: 0.02
```

A file is reviewed with your code, and it always wins its id. The [`beam.synthetic.portfolios`
page](https://github.com/splicewire/laravel-beam-synthetic/blob/main/docs/registries/beam.synthetic.portfolios.mdx)
covers the rules.

**With the builder, at runtime.** Open **Scenarios** under the lens bar, compose a draft from your blueprints
(counts, start offsets, rates), watch its revenue and MRR trend preview as you edit, then **Save as scenario**
under a kebab-case id. The scenario is stored as a central Data-declared particle in Beam's existing
shared store, and it is a lens from the next request: the bar's scenario list and the panel's **Show on board** both choose it.

Four declared operations on `exec-dashboard` serve the panel, all subject-free:

| operation | route | gate | what |
|---|---|---|---|
| `ReadScenarios` | `GET /exec/scenarios` | `exec.view` | files, then stored scenarios; the blueprints to compose from; whether the reader may save (`ScenarioCatalogData`) |
| `PreviewScenario` | `POST /exec/preview-scenario` | `exec.view` | takes a draft (`ScenarioDraftData`), answers its revenue and MRR-trend figures (`ScenarioPreviewData`), stores nothing |
| `SaveScenario` | `POST /exec/save-scenario` | `exec.scenario.manage` and `exec.view` | stores a draft under an id (`ScenarioSaveData`), replacing a stored one with that id |
| `DeleteScenario` | `POST /exec/delete-scenario` | `exec.scenario.manage` and `exec.view` | deletes a stored scenario (`ScenarioIdData`) |

- **A preview reads one table.** It builds the same in-memory source the Scenario lens reads, so its only query
  is your plan catalog, which prices it; every figure after that is computed in memory. A blueprint whose plan you
  do not sell is a 422 naming it. Its figures carry the synthesized watermark, and the response is no-store.
- **A file's id cannot be saved over.** Saving under an id a portfolio file declares is a 422, and deleting a file
  is a 422 too: a declared portfolio is part of your code. A stored row whose id a file later declares is shadowed,
  never read.
- **A stored scenario is validated whenever it is read.** One that names a blueprint you have since removed is left
  out (and a lens cookie naming it reads the default lens) rather than breaking the board.
- **Saves and deletes are logged** to the central activity trail (`log_name` `synthetic-portfolios`): who, and the
  portfolio before and after.
- A draft is capped at 12 members, 50 tenants each and a 36-month offset, because a preview runs inside the request.

`synthetic:project --portfolio=<id>` and `synthetic:materialize --portfolio=<id>` read stored scenarios too. The
builder does not materialize: writing real tenant rows stays a command an operator runs.

## Who sees the board

The realm is gated by `entitlement:exec.view`. With `splicewire/laravel-beam-accounts`' default resolver,
two grants compose it:

- an `operator` realm grant: anyone who operates the platform sees its board;
- an `exec` realm grant, the board seat: it composes `exec.view` and nothing else, so a board member
  can read the board without becoming a site author.

A member without either gets a 403 at `/exec`, and the realm is left out of their realm manifest.

Saving and deleting scenarios also needs `entitlement:exec.scenario.manage`, which the default resolver composes
from the `operator` grant only. A board seat can preview a scenario and show any scenario on the board, but cannot
store one: a stored scenario is shared board state, and the seat stays a read seat.

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
- `beam.core.entitlements.keys` (config): `exec.view` and `exec.scenario.manage`, so beam defines the
  `entitlement:exec.view` and `entitlement:exec.scenario.manage` abilities;
- `beam.nav.sections`: the `board` seat in the `exec` realm, gated on `exec.view`;
- `beam.particle.resources`: one `exec-{key}` resource per card, in the `exec` realm and the `board`
  section.

beam-ux registers the realm's dashboard (`exec-dashboard`) into `beam.particle.resources` itself, as it
does for every realm.

### Scenario particle storage — PROVISIONAL pending root d62cb04e

Stored scenarios use Synthetic's declared CentralParticleBacking resource; no dedicated portfolio
table or migration stub remains. The existing scenario key and lens values do not change; the
store translates to a separate server-managed particle UUID. Existing Read/Preview/Save/Delete
operations retain their inputs and their entitlements. Exec supplies a default
`synthetic.portfolios.manage` Gate mapping to BOTH `exec.view` and `exec.scenario.manage`;
a host's explicit ability definition remains its own. Trusted internal store/registry reads
preserve viewer and CLI behavior. No public generic CRUD is mounted.

This read/mutation policy seam is PROVISIONAL under coord04:29/04:36 pending root d62cb04e;
Exec and Synthetic land together only after the ruling. Published-table hosts (commerce-starter
and market-starter) must follow Synthetic's `docs/stored-portfolio-migration.md` lossless export,
preflight, ability-checked import and verification before retiring their old table.

## Testing

Run `composer test`.
