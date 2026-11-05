<?php

namespace Splicewire\Beam\Exec\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Rushing\DataNav\ServiceProvider as DataNavServiceProvider;
use Rushing\Doctor\ServiceProvider as DoctorServiceProvider;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\JsonNs\Laravel\JsonNsServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Exec\BeamExecServiceProvider;
use Splicewire\Beam\Sitemap\BeamSitemapServiceProvider;
use Splicewire\Beam\Tenancy\BeamTenancyServiceProvider;
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
     *
     * beam-tenancy and frame joined with the board's cards: the live source reads the tenancy `Tenant`, and a
     * card's summary is frame's `SummaryResponseData` drawn through frame's summary-provider contract.
     *
     * The doctor's provider joined with the posture registry: the default contributor runs beam's doctor manifest
     * through the doctor's `DoctorRunner`, which that provider binds.
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamExecServiceProvider::class,
            BeamTenancyServiceProvider::class,
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
            FrameServiceProvider::class,
            DoctorServiceProvider::class,
        ];
    }

    /**
     * beam-commerce's models pin `protected $connection = 'central'`, and the board reads them. Two differently
     * named connections to `:memory:` would be two databases, so the default connection IS `central`: the tenant
     * table, the commerce tables and every test's own schema all land in one sqlite database (beam-commerce's own
     * harness does the same).
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'central');
        $app['config']->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }
}
