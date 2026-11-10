# @splicewire/beam-exec

The exec board's lens bar and Scenarios panel for Beam hosts. It mirrors the PHP package `splicewire/laravel-beam-exec` by domain.

The bar is a segmented switch over the board's four lenses: Live, Truth, Scenario and Projection. Under a synthesized or projected lens it also shows a banner, and the banner cannot be dismissed. Both read the two lens operations the PHP package declares:

- `GET {base}/lens` (`ReadBoardLens`) reads the bar.
- `POST {base}/switch-lens` (`SwitchBoardLens`) chooses a lens.

The server builds the banner from the watermark on the cards' figures. This package draws it and offers no way to hide it. The bar also lists every scenario the board can show; choosing one sets `scenario:<id>`.

The Scenarios panel is the board's scenario builder, over four more declared operations:

- `GET {base}/scenarios` (`ReadScenarios`) lists the scenarios (portfolio files, then saved ones) and the blueprints to compose from.
- `POST {base}/preview-scenario` (`PreviewScenario`) previews a draft: its revenue and MRR-trend figures, watermarked. Nothing is stored.
- `POST {base}/save-scenario` (`SaveScenario`) stores a draft as `scenario:<id>`; `POST {base}/delete-scenario` (`DeleteScenario`) deletes a stored one. Both need `exec.scenario.manage`; the panel offers them only when the server says the reader may.

## The board's console page

```ts
import { beamInertiaOptions, resolveBeamPage } from '@splicewire/beam-inertia';
import { execPages } from '@splicewire/beam-exec';

beamInertiaOptions({ pages: { ...execPages(resolveBeamPage), ...pages } });
```

```php
Route::beamRealmConsole('exec', ['auth', 'verified'], page: 'exec/console');
```

`exec/console` draws the bar and the Scenarios panel above the packaged `frame/console`. The bar reads `{basename}/lens` and the panel `{basename}/scenarios`, so mount the lens and scenario operations at the realm's base. Saving or deleting a scenario makes the bar read again.

To draw the preview's MRR series with the board's own trend-card, pass frame's widget. This package does not depend on frame; without a widget the series is a table:

```ts
import { TrendCard } from '@schemastud/frame/cards';

execPages(resolveBeamPage, { trendCard: TrendCard });
```

`{ scenarios: false }` leaves the panel out.

## The bar on its own

```tsx
import { BoardLensBar } from '@splicewire/beam-exec';

<BoardLensBar url="/exec/lens" />;
```

After a switch the page reloads, so every card is read again under the new lens. To do something else instead, pass `onSwitched`. To supply your own transport, pass `client`.

## The panel on its own

```tsx
import { ScenarioPanel } from '@splicewire/beam-exec';

<ScenarioPanel url="/exec/scenarios" onChanged={() => refreshBar()} />;
```

The panel opens from its **Scenarios** button. Its composer previews after each edit (`previewDelay`, 400 ms). **Show on board** chooses the scenario's lens and reloads the page (`onShown`). To supply your own transport, pass `client`.

## Development

Run `npm run build` (tsup), `npm test` (vitest, jsdom) and `npm run typecheck`. The wire types in `src/types.ts` mirror the PHP Data classes: `BoardLensBarData`, `BoardLensOptionData`, `BoardLensBannerData` and `BoardLensChoiceData` for the bar; `ScenarioCatalogData`, `ScenarioEntryData`, `ScenarioBlueprintData`, `ScenarioDraftData`, `ScenarioSaveData`, `ScenarioIdData` and `ScenarioPreviewData` for the panel, with beam-synthetic's `PortfolioMemberData` and frame's `SummaryFigureData`. The PHP suite pins their keys.
