<?php

namespace Splicewire\Beam\Exec\Posture;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;

/**
 * One posture reading: a named check, how it stands, and a sentence saying why.
 *
 * The vocabulary is the doctor's ({@see DoctorStatus}: pass, warn, fail), so a doctor finding is a posture signal
 * with no translation, and a contributor that is not a doctor audit speaks the same three words.
 *
 * `$conclusive` is the doctor's own sibling flag ({@see Finding::$conclusive}): a check that found nothing to look at
 * reports `pass` with `conclusive: false`, because it has not found its subject clean; it has not seen it. A board
 * figure counts those as unmeasured, never as passing.
 *
 * `$source` is the key of the contributor that produced the signal (`doctor`, `determination`). The board source
 * stamps it; a contributor leaves it null.
 */
final class PostureSignal
{
    public function __construct(
        public readonly string $check,
        public readonly DoctorStatus $status,
        public readonly string $detail,
        public readonly bool $conclusive = true,
        public readonly ?string $source = null,
    ) {}

    public static function pass(string $check, string $detail): self
    {
        return new self($check, DoctorStatus::Pass, $detail);
    }

    public static function warn(string $check, string $detail): self
    {
        return new self($check, DoctorStatus::Warn, $detail);
    }

    public static function fail(string $check, string $detail): self
    {
        return new self($check, DoctorStatus::Fail, $detail);
    }

    /** A check that had nothing to look at: `pass`, but not a measurement. */
    public static function unmeasured(string $check, string $detail): self
    {
        return new self($check, DoctorStatus::Pass, $detail, conclusive: false);
    }

    public static function fromFinding(Finding $finding): self
    {
        return new self($finding->check, $finding->status, $finding->detail, $finding->conclusive);
    }

    /** The same signal, attributed to the contributor registered under `$source`. */
    public function from(string $source): self
    {
        return new self($this->check, $this->status, $this->detail, $this->conclusive, $source);
    }

    public function failing(): bool
    {
        return $this->status === DoctorStatus::Fail;
    }

    public function warning(): bool
    {
        return $this->status === DoctorStatus::Warn;
    }

    /** A conclusive pass: the check looked and found its subject clean. */
    public function passing(): bool
    {
        return $this->status === DoctorStatus::Pass && $this->conclusive;
    }

    /** @return array{check: string, status: string, detail: string, conclusive: bool, source: string|null} */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'conclusive' => $this->conclusive,
            'source' => $this->source,
        ];
    }

    /** @param  array{check: string, status: string, detail: string, conclusive?: bool, source?: string|null}  $signal */
    public static function fromArray(array $signal): self
    {
        return new self(
            $signal['check'],
            DoctorStatus::from($signal['status']),
            $signal['detail'],
            $signal['conclusive'] ?? true,
            $signal['source'] ?? null,
        );
    }
}
