<?php

use Splicewire\Beam\Config\Overrides\ConfigOverrideLock;
use Splicewire\Beam\Config\Overrides\ConfigOverrideRevision;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Exec\Tests\TestCase;

uses(TestCase::class)->in('.');

/**
 * Whether `$sql` is one of laravel-beam's config override store's own queries: its probe for, or a read of, the
 * scopes, overrides or locks table. A config read asks the store, which probes its table while it is absent, as it is
 * in this suite. A test that counts its own queries leaves these out, the way it leaves the session store's out.
 */
function isConfigStoreQuery(string $sql): bool
{
    foreach ([new ConfigOverrideScope, new ConfigOverrideRevision, new ConfigOverrideLock] as $model) {
        if (str_contains($sql, "'{$model->getTable()}'") || str_contains($sql, "\"{$model->getTable()}\"")) {
            return true;
        }
    }

    return false;
}
