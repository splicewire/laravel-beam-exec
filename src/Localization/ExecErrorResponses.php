<?php

namespace Splicewire\Beam\Exec\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Splicewire\Beam\Exec\Data\ExecErrorData;
use Throwable;

final class ExecErrorResponses
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() || ! $exception instanceof ExecAccessDeniedException) {
            return null;
        }

        return new JsonResponse(
            (new ExecErrorData($exception->getMessage(), $exception->error->code()))->toArray(),
            $exception->getStatusCode(),
            $exception->getHeaders(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }
}
