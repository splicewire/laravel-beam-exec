<?php

namespace Splicewire\Beam\Exec\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Exec\Posture\PostureRegistry;

/**
 * One concern of {@see BeamExecServiceProvider}: the board's posture contributors.
 *
 * {@see PostureRegistry} is a singleton, filled in its factory from `config('beam.exec.posture')` (a map of key to
 * contributor class) under the registrant `config:beam.exec.posture`. A package contributes by resolving the
 * registry in its own provider; the conventional spelling is
 * `$app->afterResolving(PostureRegistry::class, fn ($r) => $r->register('name', Contributor::class))`, which names
 * the class only as a string, so it costs nothing on a host that does not install exec.
 */
trait WiresPosture
{
    #[Chained('register', order: 15)]
    protected function registerPosture(): void
    {
        $this->app->singleton(PostureRegistry::class, function ($app) {
            $registry = new PostureRegistry($app);

            foreach ((array) $app['config']->get('beam.exec.posture', []) as $key => $class) {
                $registry->register((string) $key, $class, 'config:beam.exec.posture');
            }

            return $registry;
        });
    }
}
