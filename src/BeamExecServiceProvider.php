<?php

namespace Splicewire\Beam\Exec;

use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;
use Rushing\Popcorn\Registries\ScopesRegistrant;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Exec\Concerns\WiresExecRealm;

/**
 * The exec realm and its board dashboard.
 *
 * Each concern this package wires arrives as a `Wires*` trait whose methods carry
 * `#[Chained('register')]` or `#[Chained('boot')]` with a declared `order:`, the beam-ux shape. Adding
 * a concern is `use`-ing its trait; this class never hand-lists the steps. `ScopesRegistrant` brackets
 * both phases, so every registry write in them that names no `$by` records this package.
 *
 * The realm marker has to reach `beam.core.realms.classes` in the REGISTER phase, before beam-ux's
 * realm-dashboard boot link reads it, so the realm concern is a `register` link.
 */
class BeamExecServiceProvider extends PackageServiceProvider implements ChainsTraitMethodsContract
{
    use ChainsTraitMethods;
    use ScopesRegistrant;
    use WiresExecRealm;

    protected function registrant(): string
    {
        return 'splicewire/laravel-beam-exec';
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-exec')
            ->hasConfigFile(['beam/exec']);
    }

    public function packageRegistered(): void
    {
        $this->chainTraitMethods('register');
    }

    public function packageBooted(): void
    {
        $this->chainTraitMethods('boot');
    }
}
