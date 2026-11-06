<?php

namespace Splicewire\Beam\Exec\Data;

use Closure;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Ops\DeleteScenario;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;

/**
 * Which stored scenario to delete: the declared `input:` of {@see DeleteScenario}. A declared portfolio (a file) is
 * refused with a 422: it is part of the host's code, and deleting it is an edit there.
 */
#[TypeScript]
class ScenarioIdData extends BeamData
{
    public function __construct(
        public string $id,
    ) {}

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
                    $fail('That scenario is declared by the host in a file; remove the file to remove it.');
                } elseif (! $portfolios->isStored($value)) {
                    $fail('There is no stored scenario with that id.');
                }
            }],
        ];
    }
}
