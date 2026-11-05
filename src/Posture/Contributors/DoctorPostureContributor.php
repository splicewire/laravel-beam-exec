<?php

namespace Splicewire\Beam\Exec\Posture\Contributors;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Rushing\Doctor\DoctorFailed;
use Rushing\Doctor\DoctorReport;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Exec\Posture\PostureContributor;
use Splicewire\Beam\Exec\Posture\PostureSignal;

/**
 * The board's default posture: the host's readiness audits, as the doctor reports them.
 *
 * It runs every audit registered on beam's doctor manifest (`beam.doctor.audits`, where each beam package and the
 * host register their readiness checks) through the doctor's own runner, and turns each finding into one signal,
 * status and conclusiveness unchanged. The checks `splicewire:beam:doctor` keeps hardcoded in its command are not
 * registered there and are not read.
 *
 * A gate audit's failure does not stop the board: the runner's {@see DoctorFailed} carries the whole report, and
 * the board reads it. An audit that errors is already a failing finding (the runner's own).
 *
 * The audits run on a board request, so the result is cached for `beam.exec.doctor_posture_ttl` seconds (default
 * 900). Zero reads them afresh every time.
 */
final class DoctorPostureContributor implements PostureContributor
{
    public const CACHE_KEY = 'beam-exec:posture:doctor';

    public function __construct(
        private readonly BeamDoctorManifest $manifest,
        private readonly DoctorRunner $runner,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public function signals(): array
    {
        $ttl = (int) $this->config->get('beam.exec.doctor_posture_ttl', 900);

        $signals = $ttl > 0
            ? $this->cache->remember(self::CACHE_KEY, $ttl, fn (): array => $this->measure())
            : $this->measure();

        return array_map(PostureSignal::fromArray(...), $signals);
    }

    /** @return list<array{check: string, status: string, detail: string, conclusive: bool, source: string|null}> */
    private function measure(): array
    {
        return array_map(
            fn (Finding $finding): array => PostureSignal::fromFinding($finding)->toArray(),
            $this->report()->findings,
        );
    }

    private function report(): DoctorReport
    {
        try {
            return $this->runner->run($this->manifest->registrations());
        } catch (DoctorFailed $failed) {
            return $failed->report;
        }
    }
}
