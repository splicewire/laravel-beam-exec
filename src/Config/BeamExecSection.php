<?php

namespace Splicewire\Beam\Exec\Config;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The `beam.exec` Config Section (ADR-0245): the host-bound wiring for the exec realm and its board
 * dashboard — which metric cards the board stands up, where its posture/risk signals come from, and the
 * defaults the Scenario and Projection lenses read. The provider reads these keys at boot to populate
 * BoardCardRegistry and PostureRegistry and to seed the synthetic lenses.
 *
 * Every key is host-ceiling, deploy-effect: it shapes which classes the board registers and the baseline a
 * lens projects from at deploy/install time, not per-request behaviour (a lens cookie, not config, overrides a
 * reading for one request). None holds a credential.
 */
#[ConfigRoot(root: 'beam.exec', title: 'Beam exec')]
class BeamExecSection extends ConfigSection
{
    public function __construct(
        #[Title('Cards'), Description('The board\'s metric cards: a list of BoardMetric class-strings (each declared with #[BoardCard]) read into BoardCardRegistry; each becomes an `exec-{key}` resource on the board. The default is this package\'s built-in cards. A host that declares its own list REPLACES this one rather than adding to it, so re-list the built-ins you want to keep alongside any you add.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy), ArrayItems('string')]
        public array $cards,
        #[Title('Posture'), Description('Where the posture and risk cards\' signals come from: a map of signal key to PostureContributor class-string, read into PostureRegistry. The default is the host\'s readiness audits (the `doctor` contributor). A package adds its own contributor from its provider; a host that declares its own map REPLACES this one, so name the contributors you want to keep.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public BeamExecPostureConfig $posture,
        #[Title('Scenario portfolio'), Description('The beam-synthetic portfolio the Scenario lens reads when the lens cookie names none (plain `scenario`, not `scenario:<id>`): a portfolio id from PortfolioRegistry (`beam.synthetic.portfolios`). null falls back to the host\'s first available portfolio.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public mixed $scenario_portfolio,
        #[Title('Projection'), Description('The Projection lens\' default reading: revenue at the last closed month held flat per party and run forward by beam-synthetic\'s projection engine. These are the deploy-time defaults; a `projection:<id>` lens cookie selects a portfolio whose churn and expansion rates take over for that request, while the horizon, price-change schedule and new-logo settings stay as declared here. See the nested keys.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public BeamExecProjectionConfig $projection,
        #[Title('Doctor posture TTL'), Description('Seconds a doctor posture report stays fresh. While a fresh report exists a board read does not run the readiness audits inline: it serves the last report with its age and queues a refresh (RefreshDoctorPosture) when there is none or it is older than this; before the first report lands the posture reads Unmeasured. `splicewire:beam:exec:refresh-posture` refreshes it now (a schedule or deploy step). A value of 0 or less runs every audit inline on every read, which can take a minute.'), ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public int $doctor_posture_ttl,
    ) {
    }
}
