<?php

namespace Splicewire\Beam\Exec\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Exec\Ops\DeleteScenario;
use Splicewire\Beam\Exec\Ops\PreviewScenario;
use Splicewire\Beam\Exec\Ops\ReadScenarios;
use Splicewire\Beam\Exec\Ops\SaveScenario;

/**
 * The Scenarios panel: every scenario the board can show (declared files first, then stored ones), the blueprints
 * the builder can compose from, and what this reader may do. The declared `output:` of {@see ReadScenarios},
 * {@see SaveScenario} and {@see DeleteScenario}.
 *
 * Each URL is null when its operation is not mounted on this host.
 *
 * @see PreviewScenario
 */
#[TypeScript]
class ScenarioCatalogData extends BeamData
{
    /**
     * @param  list<ScenarioEntryData>  $scenarios
     * @param  list<ScenarioBlueprintData>  $blueprints
     * @param  bool  $canManage  whether this reader may save and delete scenarios (`exec.scenario.manage`)
     * @param  bool  $storeAvailable  whether this host can store scenarios (beam-synthetic's store, migrated)
     */
    public function __construct(
        #[DataCollectionOf(ScenarioEntryData::class)]
        public array $scenarios,
        #[DataCollectionOf(ScenarioBlueprintData::class)]
        public array $blueprints,
        public bool $canManage,
        public bool $storeAvailable,
        public ?string $previewUrl,
        public ?string $saveUrl,
        public ?string $deleteUrl,
        public ?string $switchUrl,
    ) {}
}
