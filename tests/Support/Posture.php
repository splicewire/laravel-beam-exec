<?php

namespace Splicewire\Beam\Exec\Tests\Support;

use RuntimeException;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Exec\Posture\PostureContributor;
use Splicewire\Beam\Exec\Posture\PostureSignal;

/** A contributor a package would register from its own provider. */
final class LedgerPostureContributor implements PostureContributor
{
    public function signals(): array
    {
        return [
            PostureSignal::pass('ledger.balanced', 'Every ledger balances.'),
            PostureSignal::fail('ledger.orphans', '2 entries name no account.'),
        ];
    }
}

/** A contributor that breaks: the board must report it, not lose every other signal. */
final class ThrowingPostureContributor implements PostureContributor
{
    public function signals(): array
    {
        throw new RuntimeException('the ledger is offline');
    }
}

/** Not a contributor at all. */
final class NotAContributor {}

/** A readiness audit a host registers on beam's doctor manifest: one of each kind of finding. */
final class MixedReadinessAudit implements DoctorAudit
{
    public static int $runs = 0;

    public function run(): array
    {
        self::$runs++;

        return [
            Finding::pass('mixed.clean', 'Clean.'),
            Finding::warn('mixed.drift', 'One key drifted.'),
            Finding::inconclusive('mixed.empty', 'No rows to look at.'),
        ];
    }
}

/** A gate audit that fails: the runner throws, and the board still reads the report. */
final class FailingGateAudit implements DoctorAudit
{
    public function run(): array
    {
        return [Finding::fail('gate.broken', 'The gate is broken.')];
    }
}
