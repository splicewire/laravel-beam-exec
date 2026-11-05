<?php

namespace Splicewire\Beam\Exec\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Exec\BeamExecServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Only this package's provider. The scaffold wires nothing into beam, beam-ux, tenancy, commerce or synthetic yet; each
     * provider a later concern needs joins this list with that concern, as the sibling harnesses do.
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamExecServiceProvider::class,
        ];
    }
}
