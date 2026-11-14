<?php

namespace Splicewire\Beam\Exec\Localization;

use InvalidArgumentException;

class ExecInvalidArgumentException extends InvalidArgumentException
{
    /** @param array<string, scalar> $params */
    public function __construct(public readonly ExecError $error, public readonly array $params = [])
    {
        parent::__construct($error->message($params));
    }
}
