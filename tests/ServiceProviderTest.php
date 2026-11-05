<?php

use Rushing\Popcorn\Concerns\Chained;
use Rushing\Popcorn\Registries\Registrant;
use Splicewire\Beam\Exec\BeamExecServiceProvider;

it('merges its config under the nested beam.exec root', function () {
    expect(config('beam.exec'))->toBeArray()
        ->and(config()->has('beam-exec'))->toBeFalse();
});

it('runs a chained concern in each phase inside the package registrant scope', function () {
    $provider = new class($this->app) extends BeamExecServiceProvider
    {
        /** @var array<string, string|null> */
        public array $seen = [];

        #[Chained('register')]
        protected function recordRegister(): void
        {
            $this->seen['register'] = Registrant::current();
        }

        #[Chained('boot')]
        protected function recordBoot(): void
        {
            $this->seen['boot'] = Registrant::current();
        }
    };

    $provider->register();
    $provider->boot();

    expect($provider->seen)->toBe([
        'register' => 'splicewire/laravel-beam-exec',
        'boot' => 'splicewire/laravel-beam-exec',
    ])->and(Registrant::current())->toBeNull();
});
