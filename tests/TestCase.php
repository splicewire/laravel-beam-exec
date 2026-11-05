<?php

namespace Splicewire\Beam\Exec\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Rushing\DataNav\ServiceProvider as DataNavServiceProvider;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\JsonNs\Laravel\JsonNsServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Sitemap\BeamSitemapServiceProvider;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * This package's provider, then beam-ux and beam with the providers their own harnesses list.
     *
     * The exec realm concern is the reason: the realm lands in beam's `RealmRegistry` from beam's boot, and its
     * dashboard is registered by beam-ux's boot link 58, so a test of the realm needs both providers really
     * booting. Testbench does not auto-discover; popcorn is listed so `RegistryIndex` is shared.
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamExecServiceProvider::class,
            BeamUxServiceProvider::class,
            BeamSitemapServiceProvider::class,
            BeamWorkflowsServiceProvider::class,
            BeamServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            VersioningServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            DataNavServiceProvider::class,
            JsonNsServiceProvider::class,
            PopcornServiceProvider::class,
            DataFiltersServiceProvider::class,
        ];
    }
}
