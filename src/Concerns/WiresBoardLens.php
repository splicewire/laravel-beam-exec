<?php

namespace Splicewire\Beam\Exec\Concerns;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Exec\Board\Lens\NoStoreWatermarkedResponses;
use Splicewire\Beam\Exec\Board\Scenarios\ScenarioBuilder;
use Splicewire\Beam\Exec\Ops\ReadBoardLens;
use Splicewire\Beam\Exec\Ops\SwitchBoardLens;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Synthetic\Data\StoredPortfolioData;

/**
 * One concern of {@see BeamExecServiceProvider}: the lens bar.
 *
 * ## Boot phase: the two lens operations and the no-store rule
 *
 * {@see ReadBoardLens} and {@see SwitchBoardLens} are declared `#[ParticleOp]`s on the `exec-dashboard` resource.
 * Their declaration root (`src/Ops`) is scanned at this boot link, before any host route file loads, so the host's
 * one mount line finds them:
 *
 *     Particle::ops('exec', 'exec-dashboard', [ReadBoardLens::class, SwitchBoardLens::class]);
 *
 * The scenario builder's four operations (`ReadScenarios`, `PreviewScenario`, `SaveScenario`, `DeleteScenario`) are
 * declared in the same root, on the same resource, and are found by the same scan:
 *
 *     Particle::ops('exec', 'exec-dashboard', [ReadScenarios::class, PreviewScenario::class, SaveScenario::class, DeleteScenario::class]);
 *
 * {@see NoStoreWatermarkedResponses} listens on the kernel's `RequestHandled`, so every response that carried a
 * synthesized or projected figure, whichever route served it, is `Cache-Control: no-store, private`.
 */
trait WiresBoardLens
{
    #[Chained('boot', order: 30)]
    protected function bootBoardLens(): void
    {
        // PROVISIONAL owner-review 18 policy seam, pending root d62cb04e.
        // Synthetic owns the ability; Exec supplies its existing two-entitlement default.
        $gate = $this->app->make(Gate::class);
        if (! $gate->has(StoredPortfolioData::WRITE_ABILITY)) {
            $gate->define(StoredPortfolioData::WRITE_ABILITY, fn ($actor): bool => ScenarioBuilder::mayManage($gate, $actor));
        }

        Event::listen(RequestHandled::class, [NoStoreWatermarkedResponses::class, 'handle']);

        if (! $this->app->bound(ParticleOperationRegistry::class) || ! class_exists(AttributedParticleDiscovery::class)) {
            return;
        }

        $this->app->make(AttributedParticleDiscovery::class)->discover(paths: [dirname(__DIR__).'/Ops']);
    }
}
