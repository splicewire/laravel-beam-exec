<?php

namespace Splicewire\Beam\Exec\Posture;

use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Posture\Contributors\DoctorPostureContributor;

/**
 * One source of the board's posture: something that can say, in pass / warn / fail, how a part of the estate stands.
 *
 * A contributor is registered in {@see PostureRegistry} (`beam.exec.posture`) under a key, and read by the live
 * {@see BoardSource} when a posture or risk card is drawn. The package registers {@see DoctorPostureContributor},
 * which reads the host's readiness audits. A package that knows something the doctor does not registers its own
 * contributor DOWN into the registry from its own provider; exec never names it.
 *
 * A contributor may read the database: it is read by the live source, not by a card. It should be cheap, or serve a
 * stored reading, because it runs on a board request; the doctor's serves its last report and refreshes it off the
 * request. A contributor that throws is reported as one failing signal rather than blanking the board.
 */
interface PostureContributor
{
    /** @return list<PostureSignal> */
    public function signals(): array;
}
