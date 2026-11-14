<?php

namespace Splicewire\Beam\Exec\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
class ExecErrorData extends BeamData
{
    public function __construct(public string $message, public string $code) {}
}
