<?php

// splicewire/laravel-beam-exec, merged under `beam.exec.*`.
//
// Each key lands with the code that reads it, in the same commit, so no key exists without a reader.

use Splicewire\Beam\Exec\Board\Cards\TenantsCard;

return [

    // The board's cards: a list of BoardMetric classes, each declared with #[BoardCard], read into
    // BoardCardRegistry (`beam.exec.cards`). Each becomes an `exec-{key}` resource on the board. The default is
    // this package's built-in cards; a host that declares its own list replaces it, so list them too to keep them.
    'cards' => [
        TenantsCard::class,
    ],

];
