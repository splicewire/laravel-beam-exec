<?php

namespace Splicewire\Beam\Exec\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One blueprint the scenario builder can pick, from beam-synthetic's `BlueprintRegistry`.
 *
 * @see ScenarioCatalogData
 */
#[TypeScript]
class ScenarioBlueprintData extends BeamData
{
    /**
     * @param  string  $scenario  the curve shape (`steady-ramp`, `seasonal`, `dip-then-recover`)
     * @param  int  $windowMonths  how many months the blueprint's story runs
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $planSlug,
        public string $scenario,
        public int $windowMonths,
    ) {}
}
