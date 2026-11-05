<?php

namespace Splicewire\Beam\Exec\Posture;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Splicewire\Beam\Exec\Posture\Contributors\DoctorPostureContributor;

/**
 * Run the host's readiness audits and keep the report the board reads.
 *
 * {@see DoctorPostureContributor} queues it when a board read finds no report, or one older than its ttl, so the read
 * itself never waits for the audits. It carries no state: the audits it runs are the manifest's when it runs. A job that
 * finds a fresh report already landed (an earlier job ran first) runs nothing.
 */
final class RefreshDoctorPosture implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** A full doctor run took 34 to 65 seconds on a fresh starter host; the default 60 s job timeout is too close. */
    public int $timeout = 600;

    public int $tries = 1;

    public function handle(DoctorPostureContributor $doctor): void
    {
        $doctor->refreshUnlessFresh();
    }
}
