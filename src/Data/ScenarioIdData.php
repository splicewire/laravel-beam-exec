<?php

namespace Splicewire\Beam\Exec\Data;

use Closure;
use Illuminate\Validation\Validator;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Localization\ExecValidationException;
use Splicewire\Beam\Exec\Ops\DeleteScenario;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;

/**
 * Which stored scenario to delete: the declared `input:` of {@see DeleteScenario}. A declared portfolio (a file) is
 * refused with a 422: it is part of the host's code, and deleting it is an edit there.
 */
#[TypeScript, Title('Scenario to delete')]
class ScenarioIdData extends BeamData
{
    public function __construct(
        #[Description('The id of the stored scenario to delete (the <id> of its lens value scenario:<id>). A portfolio the host declares in a file cannot be deleted here.')]
        public string $id,
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
            'id' => ['required', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                $portfolios = app(PortfolioRegistry::class);

                if ($portfolios->declares($value)) {
                    $fail(__('beam-exec::messages.validation_that_scenario_is_declared_by_the_host_in_a_file_remove_the_file_to_remove_it'));
                } elseif (! $portfolios->isStored($value)) {
                    $fail(__('beam-exec::messages.validation_there_is_no_stored_scenario_with_that_id'));
                }
            }],
        ];
    }
}
