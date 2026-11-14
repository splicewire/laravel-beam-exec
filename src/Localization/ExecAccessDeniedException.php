<?php

namespace Splicewire\Beam\Exec\Localization;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ExecAccessDeniedException extends AccessDeniedHttpException
{
    public function __construct(public readonly ExecError $error)
    {
        parent::__construct($error->message());
    }
}
