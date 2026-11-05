# @splicewire/beam-exec

The exec board's lens bar for Beam hosts. It mirrors the PHP package `splicewire/laravel-beam-exec` by domain.

The bar is a segmented switch over the board's four lenses: Live, Truth, Scenario and Projection. Under a synthesized or projected lens it also shows a banner, and the banner cannot be dismissed. Both read the two lens operations the PHP package declares:

- `GET {base}/lens` (`ReadBoardLens`) reads the bar.
- `POST {base}/switch-lens` (`SwitchBoardLens`) chooses a lens.

The server builds the banner from the watermark on the cards' figures. This package draws it and offers no way to hide it.

## The board's console page

```ts
import { beamInertiaOptions, resolveBeamPage } from '@splicewire/beam-inertia';
import { execPages } from '@splicewire/beam-exec';

beamInertiaOptions({ pages: { ...execPages(resolveBeamPage), ...pages } });
```

```php
Route::beamRealmConsole('exec', ['auth', 'verified'], page: 'exec/console');
```

`exec/console` draws the bar above the packaged `frame/console`. The bar reads `{basename}/lens`, so mount the lens operations at the realm's base.

## The bar on its own

```tsx
import { BoardLensBar } from '@splicewire/beam-exec';

<BoardLensBar url="/exec/lens" />;
```

After a switch the page reloads, so every card is read again under the new lens. To do something else instead, pass `onSwitched`. To supply your own transport, pass `client`.

## Development

Run `npm run build` (tsup), `npm test` (vitest, jsdom) and `npm run typecheck`. The wire types in `src/types.ts` mirror the PHP Data classes `BoardLensBarData`, `BoardLensOptionData`, `BoardLensBannerData` and `BoardLensChoiceData`. The PHP suite pins their keys.
