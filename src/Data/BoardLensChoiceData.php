<?php

namespace Splicewire\Beam\Exec\Data;

use Closure;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Ops\SwitchBoardLens;

/**
 * The lens a board member chooses: the declared `input:` of {@see SwitchBoardLens}.
 *
 * `$lens` is a lens cookie value ({@see BoardLens}): `live`, `truth`, `scenario`, `scenario:<portfolio>`,
 * `projection` or `projection:<portfolio>`. Validation refuses anything the board would not honour, which is
 * stricter than reading the cookie: a stale cookie reads as the default lens so the board is never stranded, but a
 * request to CHOOSE an unknown lens, or a portfolio this host does not declare, is a 422. Accepting it would set a
 * cookie the next request silently ignores.
 */
#[TypeScript]
class BoardLensChoiceData extends BeamData
{
    public function __construct(
        public string $lens,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'lens' => ['required', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || app(BoardLensResolver::class)->choose($value) === null) {
                    $fail('The board has no such lens. Choose live, truth, scenario or projection, with a portfolio this host declares.');
                }
            }],
        ];
    }
}
