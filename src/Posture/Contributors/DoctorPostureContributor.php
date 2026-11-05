<?php

namespace Splicewire\Beam\Exec\Posture\Contributors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Rushing\Doctor\DoctorFailed;
use Rushing\Doctor\DoctorReport;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Exec\Console\RefreshPostureCommand;
use Splicewire\Beam\Exec\Posture\PostureContributor;
use Splicewire\Beam\Exec\Posture\PostureSignal;
use Splicewire\Beam\Exec\Posture\RefreshDoctorPosture;

/**
 * The board's default posture: the host's readiness audits, as the doctor last reported them.
 *
 * The audits are every registration on beam's doctor manifest (`beam.doctor.audits`, where each beam package and the
 * host register their readiness checks), run through the doctor's own runner. Each finding becomes one signal, with
 * its status and conclusiveness unchanged. The checks `splicewire:beam:doctor` keeps hardcoded in its command are not
 * registered there and are not read.
 *
 * A gate audit's failure does not stop the board: the runner's {@see DoctorFailed} carries the whole report, and
 * the board reads it. An audit that errors is already a failing finding (the runner's own).
 *
 * **A board read never runs the audits.** A full doctor run is source scans as much as state checks, and it took
 * 34 to 65 seconds on a fresh starter host, so running it inline made the first board read after a cache clear wait
 * that long. A read serves the last report from the cache instead, and every signal carries the time the report was
 * measured ({@see PostureSignal::$measuredAt}), which the posture card draws as the report's age.
 *
 * - **No report yet:** one unmeasured signal ({@see UNMEASURED}), so the card reads `Unmeasured`, never `Pass`, and
 *   a refresh is queued.
 * - **A report older than `beam.exec.doctor_posture_ttl` seconds (default 900):** it is still served, with its age,
 *   and a refresh is queued.
 * - **A ttl of 0:** the audits run inline on every read, as before. Only a host that has chosen to wait asks for it.
 *
 * A refresh is the queued {@see RefreshDoctorPosture} job, at most one outstanding per ttl. On a `sync` queue it runs
 * after the response is sent, so it never holds the read. A host can also refresh on a schedule, or after a deploy,
 * with {@see RefreshPostureCommand}.
 */
final class DoctorPostureContributor implements PostureContributor
{
    /** Where the last report lives: `{measured_at: ISO-8601, signals: list<signal array>}`, kept until replaced. */
    public const CACHE_KEY = 'beam-exec:posture:doctor-report';

    /** Set while a refresh is queued, so a burst of cold reads queues one run, not one per read. */
    public const QUEUED_KEY = 'beam-exec:posture:doctor-refresh-queued';

    /** The one signal a read returns before the first report lands. */
    public const UNMEASURED = 'doctor.unmeasured';

    public function __construct(
        private readonly BeamDoctorManifest $manifest,
        private readonly DoctorRunner $runner,
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly Dispatcher $bus,
    ) {}

    public function signals(): array
    {
        $ttl = $this->ttl();

        if ($ttl <= 0) {
            return $this->signalsOf($this->measure(), CarbonImmutable::now());
        }

        $report = $this->report();

        if ($report === null) {
            $this->refreshLater($ttl);

            return [PostureSignal::unmeasured(
                self::UNMEASURED,
                'The readiness audits have not reported yet. A refresh is queued, and the posture reads when it lands.',
            )];
        }

        [$measuredAt, $signals] = $report;

        if ($measuredAt->addSeconds($ttl)->isPast()) {
            $this->refreshLater($ttl);
        }

        return $this->signalsOf($signals, $measuredAt);
    }

    /** Run every audit now and keep the report. The queued job and the refresh command call this; a read never does. */
    public function refresh(): void
    {
        $measuredAt = CarbonImmutable::now();
        $signals = $this->measure();

        $this->cache->forever(self::CACHE_KEY, [
            'measured_at' => $measuredAt->toIso8601String(),
            'signals' => $signals,
        ]);
        $this->cache->forget(self::QUEUED_KEY);
    }

    /**
     * Refresh unless a report fresher than the ttl has landed since the refresh was queued, so jobs queued by reads
     * the queue had not caught up with run the audits once, not once each. Returns whether it ran them.
     */
    public function refreshUnlessFresh(): bool
    {
        $measuredAt = $this->measuredAt();

        if ($measuredAt !== null && ! $measuredAt->addSeconds(max(1, $this->ttl()))->isPast()) {
            $this->cache->forget(self::QUEUED_KEY);

            return false;
        }

        $this->refresh();

        return true;
    }

    /** The last report's measured time, or null when none has landed. */
    public function measuredAt(): ?CarbonImmutable
    {
        return $this->report()[0] ?? null;
    }

    private function ttl(): int
    {
        return (int) $this->config->get('beam.exec.doctor_posture_ttl', 900);
    }

    /**
     * The cached report, or null when there is none or it is not in this shape (a cache written by another version).
     *
     * @return array{CarbonImmutable, list<array<string, mixed>>}|null
     */
    private function report(): ?array
    {
        $report = $this->cache->get(self::CACHE_KEY);

        if (! is_array($report) || ! is_string($report['measured_at'] ?? null) || ! is_array($report['signals'] ?? null)) {
            return null;
        }

        return [CarbonImmutable::parse($report['measured_at']), array_values($report['signals'])];
    }

    private function refreshLater(int $ttl): void
    {
        if (! $this->cache->add(self::QUEUED_KEY, true, $ttl)) {
            return; // one is already queued
        }

        $job = new RefreshDoctorPosture;

        if ($this->config->get('queue.connections.'.$this->config->get('queue.default').'.driver') === 'sync') {
            $this->bus->dispatchAfterResponse($job);

            return;
        }

        $this->bus->dispatch($job);
    }

    /**
     * @param  list<array<string, mixed>>  $signals
     * @return list<PostureSignal>
     */
    private function signalsOf(array $signals, CarbonImmutable $measuredAt): array
    {
        return array_map(
            fn (array $signal): PostureSignal => PostureSignal::fromArray($signal)->measured($measuredAt),
            $signals,
        );
    }

    /** @return list<array{check: string, status: string, detail: string, conclusive: bool, source: string|null}> */
    private function measure(): array
    {
        return array_map(
            fn (Finding $finding): array => PostureSignal::fromFinding($finding)->toArray(),
            $this->doctorReport()->findings,
        );
    }

    private function doctorReport(): DoctorReport
    {
        try {
            return $this->runner->run($this->manifest->registrations());
        } catch (DoctorFailed $failed) {
            return $failed->report;
        }
    }
}
