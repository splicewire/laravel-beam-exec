<?php

namespace Splicewire\Beam\Exec\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Ops\PreviewScenario;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Data\PortfolioMemberData;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;

/**
 * A scenario as the builder composes it, before it has an id: the declared `input:` of {@see PreviewScenario}, and
 * the body of {@see ScenarioSaveData}.
 *
 * It is beam-synthetic's portfolio shape ({@see ScenarioPortfolioData}) without the id: blueprint members, each with
 * a count and a start offset, plus optional monthly churn and expansion rates. Validation answers a 422 per field: a
 * member must name a blueprint this host declares, and the counts are capped ({@see MAX_MEMBERS}, {@see MAX_COUNT},
 * {@see MAX_OFFSET}) because a preview runs the whole roster in memory inside the request.
 */
#[TypeScript]
class ScenarioDraftData extends BeamData
{
    public const MAX_MEMBERS = 12;

    public const MAX_COUNT = 50;

    public const MAX_OFFSET = 36;

    /**
     * @param  list<PortfolioMemberData>  $members
     */
    public function __construct(
        public string $label,
        #[DataCollectionOf(PortfolioMemberData::class)]
        public array $members,
        public ?float $churnRate = null,
        public ?float $expansionRate = null,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            'members' => ['required', 'array', 'min:1', 'max:'.self::MAX_MEMBERS],
            'members.*.blueprint' => ['required', 'string', Rule::in(array_keys(app(BlueprintRegistry::class)->all()))],
            'members.*.count' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_COUNT],
            'members.*.startOffsetMonths' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_OFFSET],
            'churnRate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'expansionRate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'members.*.blueprint.in' => 'Choose a blueprint this host declares.',
        ];
    }

    /** The draft as a portfolio under `$id`, checked by beam-synthetic's own rules. */
    public function toPortfolio(string $id): ScenarioPortfolioData
    {
        return ScenarioPortfolioData::from([
            'id' => $id,
            'label' => $this->label,
            'members' => array_map(fn (PortfolioMemberData $member): array => $member->toArray(), $this->members),
            'churnRate' => $this->churnRate,
            'expansionRate' => $this->expansionRate,
        ]);
    }
}
