<?php

namespace Splicewire\Beam\Exec;

use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;
use Rushing\Popcorn\Registries\ScopesRegistrant;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Exec\Config\BeamExecSection;
use Splicewire\Beam\Exec\Concerns\WiresBoardCards;
use Splicewire\Beam\Exec\Concerns\WiresBoardLens;
use Splicewire\Beam\Exec\Concerns\WiresExecRealm;
use Splicewire\Beam\Exec\Concerns\WiresExecText;
use Splicewire\Beam\Exec\Concerns\WiresPosture;
use Splicewire\Beam\Exec\Console\MakeBoardCardCommand;
use Splicewire\Beam\Exec\Console\RefreshPostureCommand;

/**
 * The exec realm, its board dashboard, the board's cards and the posture they count.
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
    use WiresBoardCards;
    use WiresBoardLens;
    use WiresExecRealm;
    use WiresExecText;
    use WiresPosture;

    protected function registrant(): string
    {
        return 'splicewire/laravel-beam-exec';
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-exec')
            ->hasConfigFile(['beam/exec'])
            ->hasCommands([MakeBoardCardCommand::class, RefreshPostureCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->chainTraitMethods('register');

        // Register the beam.exec Config Section (ADR-0245) once beam-core's registry resolves. Deferred
        // via callAfterResolving so a host with no ConfigSectionRegistry bound simply skips it; side-effect-
        // safe at boot.
        $this->callAfterResolving(
            ConfigSectionRegistry::class,
            static function (ConfigSectionRegistry $sections): void {
                $sections->section(
                    BeamExecSection::class,
                    owner: 'splicewire/laravel-beam-exec',
                    packageFile: dirname(__DIR__).'/config/beam/exec.php',
                );
            },
        );
    }

    public function packageBooted(): void
    {
        $this->chainTraitMethods('boot');
    }
}
