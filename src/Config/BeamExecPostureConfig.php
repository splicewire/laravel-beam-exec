<?php

namespace Splicewire\Beam\Exec\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The `beam.exec.posture` map (nested in {@see BeamExecSection}): each key names a posture signal the
 * posture and risk cards count, bound to the PostureContributor class that produces it.
 */
class BeamExecPostureConfig extends Data
{
    public function __construct(
        #[Title('Doctor'), Description('The PostureContributor class for the `doctor` signal the posture and risk cards read — the host\'s readiness audits. Defaults to this package\'s DoctorPostureContributor; a host rebinds it to read its own readiness source.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public string $doctor,
    ) {
    }
}
