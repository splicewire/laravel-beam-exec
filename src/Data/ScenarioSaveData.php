<?php

namespace Splicewire\Beam\Exec\Data;

use Closure;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Exec\Ops\SaveScenario;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\PortfolioMemberData;
use Splicewire\Beam\Synthetic\Store\StoredPortfolios;

/**
 * A scenario to store: the declared `input:` of {@see SaveScenario}. A {@see ScenarioDraftData} plus the id it is
 * saved under, which becomes the lens value `scenario:<id>`.
 *
 * The id is kebab-case (the lens cookie's rule), and it may not be an id the host DECLARES (a portfolio file, or one
 * registered in code): a declared portfolio always wins its id, so saving under one is refused here rather than
 * stored and never read. Saving under an id already stored replaces that stored scenario.
 */
#[TypeScript, Title('Scenario to save')]
class ScenarioSaveData extends ScenarioDraftData
{
    /**
     * @param  list<PortfolioMemberData>  $members
     */
    public function __construct(
        #[Description('The id to store the scenario under, in kebab-case (lowercase letters, digits and single hyphens, for example board-upside); it becomes the lens value scenario:<id>. An id the host declares in a portfolio file is refused; an id already stored is replaced.')]
        public string $id,
        string $label,
        #[DataCollectionOf(PortfolioMemberData::class)]
        array $members,
        ?float $churnRate = null,
        ?float $expansionRate = null,
    ) {
        parent::__construct($label, $members, $churnRate, $expansionRate);
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:'.StoredPortfolios::ID_MAX, 'regex:'.StoredPortfolios::ID_PATTERN, function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && app(PortfolioRegistry::class)->declares($value)) {
                    $fail('That id belongs to a portfolio the host declares in a file. Choose another id.');
                }
            }],
            ...parent::rules(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'id.regex' => 'Use lowercase letters, digits and single hyphens (for example board-upside).',
            ...parent::messages(),
        ];
    }
}
