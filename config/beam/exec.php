<?php

// splicewire/laravel-beam-exec, merged under `beam.exec.*`.
//
// Each key lands with the code that reads it, in the same commit, so no key exists without a reader.

use Splicewire\Beam\Exec\Board\Cards\PostureCard;
use Splicewire\Beam\Exec\Board\Cards\RiskCard;
use Splicewire\Beam\Exec\Board\Cards\TenantsCard;
use Splicewire\Beam\Exec\Posture\Contributors\DoctorPostureContributor;

return [

    // The board's cards: a list of BoardMetric classes, each declared with #[BoardCard], read into
    // BoardCardRegistry (`beam.exec.cards`). Each becomes an `exec-{key}` resource on the board. The default is
    // this package's built-in cards; a host that declares its own list replaces it, so list them too to keep them.
    'cards' => [
        TenantsCard::class,
        PostureCard::class,
        RiskCard::class,
    ],

    // Where the posture and risk cards' signals come from: a map of key to PostureContributor class, read into
    // PostureRegistry (`beam.exec.posture`). The default is the host's readiness audits. A package adds its own
    // contributor from its provider; a host that declares its own map replaces this one, so name `doctor` to keep it.
    'posture' => [
        'doctor' => DoctorPostureContributor::class,
    ],

    // Seconds the doctor-backed posture is cached for: it runs every registered readiness audit, on a board request.
    // 0 runs them afresh on every read.
    'doctor_posture_ttl' => 900,

];
