<?php

namespace Splicewire\Beam\Exec\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One segment of the lens bar's switch.
 *
 * @see BoardLensBarData
 */
#[TypeScript]
class BoardLensOptionData extends BeamData
{
    /**
     * @param  string  $value  the lens cookie value choosing it (`live`, `truth`, `scenario`, `projection`); what the switch posts
     * @param  string  $label  the segment's label
     * @param  string  $description  one line on what the lens reads
     * @param  bool  $active  whether it is the lens this request reads
     * @param  bool  $available  whether this host can honour it (a Scenario needs a declared portfolio)
     */
    public function __construct(
        public string $value,
        public string $label,
        public string $description,
        public bool $active,
        public bool $available,
    ) {}
}
