<?php

namespace Splicewire\Beam\Exec\Board\Lens;

use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Answers `Cache-Control: no-store, private` on every response that carried a synthesized or projected figure, or
 * that set the board's lens.
 *
 * {@see BoardLensBar::mark()} flags the request: the card registry flags it whenever it measures a card from a
 * watermarked source, so a card's page, a dashboard summary and the lens bar are all covered by the one place that
 * draws figures, whichever route served them. The switch flags its own response, which sets the lens cookie.
 *
 * It listens on the kernel's `RequestHandled` rather than sitting in a route group, because the card summaries are
 * served by frame's and beam's routes, which this package does not mount. The event fires after the middleware
 * pipeline, so no later middleware rewrites the header.
 */
final class NoStoreWatermarkedResponses
{
    public const CACHE_CONTROL = 'no-store, private';

    public function handle(RequestHandled $event): void
    {
        if ($event->request->attributes->get(BoardLensBar::NO_STORE) === true) {
            $event->response->headers->set('Cache-Control', self::CACHE_CONTROL);
        }
    }
}
