<?php

namespace Splicewire\Beam\Exec\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Exec\Posture\Contributors\DoctorPostureContributor;

/**
 * Run the readiness audits the board's posture reads, now, and keep the report.
 *
 * A board read never runs them ({@see DoctorPostureContributor}); it queues a refresh when the report is missing or
 * older than `beam.exec.doctor_posture_ttl`. A host that would rather not depend on a queue worker schedules this
 * command instead, and a deploy can run it so the first board read already has a report:
 *
 *     Schedule::command('splicewire:beam:exec:refresh-posture')->everyFifteenMinutes();
 */
class RefreshPostureCommand extends Command
{
    protected $signature = 'splicewire:beam:exec:refresh-posture';

    protected $description = 'Run the readiness audits the exec board\'s posture reads, and keep the report';

    public function handle(DoctorPostureContributor $doctor): int
    {
        $started = hrtime(true);
        $doctor->refresh();

        $this->components->info(sprintf(
            'Posture report measured at %s, in %d ms.',
            $doctor->measuredAt()?->toIso8601String() ?? 'an unknown time',
            (int) ((hrtime(true) - $started) / 1e6),
        ));

        return self::SUCCESS;
    }
}
