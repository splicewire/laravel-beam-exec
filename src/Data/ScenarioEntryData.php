<?php

namespace Splicewire\Beam\Exec\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Synthetic\Data\PortfolioMemberData;

/**
 * One scenario the board can show, as the Scenarios panel lists it.
 *
 * @see ScenarioCatalogData
 */
#[TypeScript]
class ScenarioEntryData extends BeamData
{
    public const FILE = 'file';

    public const STORED = 'stored';

    /**
     * @param  string  $id  the portfolio id
     * @param  string  $lens  the lens value that shows it on the board (`scenario:<id>`)
     * @param  string  $source  `file` (declared by the host, read-only here) or `stored` (saved with the builder)
     * @param  list<PortfolioMemberData>  $members
     * @param  bool  $active  whether the board reads it now
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $lens,
        public string $source,
        #[DataCollectionOf(PortfolioMemberData::class)]
        public array $members,
        public ?float $churnRate,
        public ?float $expansionRate,
        public bool $active,
    ) {}
}
