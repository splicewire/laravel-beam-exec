<?php

namespace Splicewire\Beam\Exec\Data;

use Closure;
use Illuminate\Validation\Validator;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Board\Lens\BoardLens;
use Splicewire\Beam\Exec\Board\Lens\BoardLensResolver;
use Splicewire\Beam\Exec\Localization\ExecValidationException;
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
        #[Description('The lens to read the board through: live, truth, scenario or projection, optionally followed by a colon and a portfolio this host declares (scenario:<portfolio>, projection:<portfolio>).')]
        public string $lens,
    ) {}

    public static function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->setException(ExecValidationException::class);
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'lens' => ['required', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || app(BoardLensResolver::class)->choose($value) === null) {
                    $fail(__('beam-exec::messages.validation_the_board_has_no_such_lens_choose_live_truth_scenario_or_projection_with_a_portfolio_this_host_declares'));
                }
            }],
        ];
    }
}
