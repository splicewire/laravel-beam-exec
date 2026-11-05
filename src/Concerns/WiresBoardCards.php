<?php

namespace Splicewire\Beam\Exec\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Exec\Board\BoardCardBacking;
use Splicewire\Beam\Exec\Board\BoardCardDefinition;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardCardSummaryProvider;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\Sources\LiveBoardSource;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Realm\ExecRealm;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * One concern of {@see BeamExecServiceProvider}: the board's cards.
 *
 * ## Register phase: the card registry and the source
 *
 * {@see BoardCardRegistry} is a singleton, so a card a host adds from its own provider's `register()` lands in the
 * instance the boot link reads. Its config entries (`beam.exec.cards`, a list of card classes) are added in the
 * factory, under the registrant `config:beam.exec.cards`.
 *
 * {@see BoardSource} is bound SCOPED: one source per request (or per job), so a memoized read is never served to the
 * next request. The package binds {@see LiveBoardSource::reportable()}: the live reading, or the truth while
 * beam-tenancy's reporting toggle excludes synthetic tenants (read when the request first asks, so a flip needs no
 * redeploy). Whatever selects the board's lens rebinds it.
 *
 * ## Boot phase: one resource per card
 *
 * Each card becomes a particle resource `exec-{key}` in the `exec` realm, seated in the `board` section, read through
 * {@see BoardCardBacking} and summarized by {@see BoardCardSummaryProvider}. Its read gate is the card's ability,
 * which defaults to the realm gate, so a card is never a softer door than the board.
 *
 * The resources are registered imperatively because the backing is an instance carrying the card's key (the attribute
 * slot is a class-string), the reason the particle doctrine gives for `register()`. They are registered at this boot
 * link, which is before any host route file loads, so `Route::beamRealmConsole('exec')` mounts each card's segment;
 * and again on `booted()`, idempotently, for a card added later.
 */
trait WiresBoardCards
{
    #[Chained('register', order: 20)]
    protected function registerBoardCards(): void
    {
        $this->app->singleton(BoardCardRegistry::class, function ($app) {
            $registry = new BoardCardRegistry($app);

            foreach ((array) $app['config']->get('beam.exec.cards', []) as $class) {
                $registry->add($class, 'config:beam.exec.cards');
            }

            return $registry;
        });

        $this->app->scoped(BoardSource::class, fn (): BoardSource => LiveBoardSource::reportable());
    }

    #[Chained('boot', order: 20)]
    protected function bootBoardCards(): void
    {
        if (! $this->app->bound(ParticleResourceRegistry::class)) {
            return;
        }

        $this->registerBoardCardResources();
        $this->app->booted(fn () => $this->registerBoardCardResources());
    }

    /** One `exec-{key}` resource per card that has none yet. */
    private function registerBoardCardResources(): void
    {
        $particles = $this->app->make(ParticleResourceRegistry::class);

        foreach ($this->app->make(BoardCardRegistry::class)->definitions() as $definition) {
            if ($particles->has($definition->resourceKey())) {
                continue;
            }

            $particles->register($this->cardResource($definition), [ExecRealm::KEY], by: $this->registrant());
        }
    }

    private function cardResource(BoardCardDefinition $definition): ParticleResource
    {
        return new ParticleResource(
            key: $definition->resourceKey(),
            backing: new BoardCardBacking($definition->key()),
            data: BoardFigureData::class,
            label: $definition->card->label,
            policy: $definition->ability(),
            icon: $definition->card->icon,
            section: ExecRealm::SECTION,
            navOrder: $definition->card->order,
            readOnly: true,
            editable: false,
            // A figure has no page of its own: the card's page lists its figures, and that is the whole read.
            showable: false,
            frame: true,
            summaryProvider: BoardCardSummaryProvider::class,
        );
    }
}
