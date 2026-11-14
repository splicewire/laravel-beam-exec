<?php

namespace Splicewire\Beam\Exec\Concerns;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Exec\Localization\ExecErrorResponses;

trait WiresExecText
{
    #[Chained('boot', order: 90)]
    protected function bootExecText(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'beam-exec');
        $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
            $handler->renderable(ExecErrorResponses::render(...));
        });
    }
}
