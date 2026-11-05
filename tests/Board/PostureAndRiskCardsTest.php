<?php

use Carbon\CarbonImmutable;
use Schemastud\Frame\Data\SummaryFigureData;
use Splicewire\Beam\Exec\Board\BoardCardRegistry;
use Splicewire\Beam\Exec\Board\BoardCardSummaryProvider;
use Splicewire\Beam\Exec\Board\BoardSource;
use Splicewire\Beam\Exec\Board\BoardTenant;
use Splicewire\Beam\Exec\Board\Cards\PostureCard;
use Splicewire\Beam\Exec\Board\Cards\RiskCard;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Posture\PostureSignal;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** @return list<array{string, int|float|string, string|null}> */
function figureRows(array $figures): array
{
    return array_map(fn (BoardFigureData $f): array => [$f->id, $f->value, $f->tone], $figures);
}

function riskTenant(string $id, string $status = 'active', bool $suspended = false, bool $synthetic = false): BoardTenant
{
    return new BoardTenant($id, ucfirst($id), $status, $synthetic, $suspended, CarbonImmutable::parse('2024-01-01'));
}

it('registers the posture and risk cards on the board after the headcount', function () {
    $particles = app(ParticleResourceRegistry::class);

    expect(app(BoardCardRegistry::class)->cardKeys())->toBe(['tenants', 'revenue', 'mrr-trend', 'growth', 'plan-mix', 'usage', 'posture', 'risk'])
        ->and($particles->keysForRealm('exec'))->toContain('exec-posture', 'exec-risk')
        ->and($particles->get('exec-posture')->section)->toBe('board')
        ->and($particles->get('exec-posture')->navOrder)->toBe(40)
        ->and($particles->get('exec-risk')->navOrder)->toBe(50)
        ->and($particles->get('exec-risk')->policy)->toBe('entitlement:exec.view');
});

it('heads the posture card with the worst status and counts each kind of signal', function () {
    $figures = (new PostureCard)->measure(new InMemoryBoardSource(posture: [
        PostureSignal::pass('a', 'clean'),
        PostureSignal::pass('b', 'clean'),
        PostureSignal::warn('c', 'drift'),
        PostureSignal::fail('d', 'broken'),
        PostureSignal::unmeasured('e', 'nothing to look at'),
    ]));

    expect(figureRows($figures))->toBe([
        ['status', 'Fail', SummaryFigureData::TONE_DANGER],
        ['passing', 2, SummaryFigureData::TONE_ACTIVE],
        ['warnings', 1, SummaryFigureData::TONE_WARN],
        ['failing', 1, SummaryFigureData::TONE_DANGER],
        ['unmeasured', 1, null],
    ]);
});

it('reads warn when nothing fails, pass when everything measured passes, and unmeasured when nothing was measured', function (array $signals, string $headline, ?string $tone) {
    $status = (new PostureCard)->measure(new InMemoryBoardSource(posture: $signals))[0];

    expect([$status->value, $status->tone])->toBe([$headline, $tone]);
})->with([
    'a warning' => [[PostureSignal::pass('a', 'ok'), PostureSignal::warn('b', 'drift')], 'Warn', SummaryFigureData::TONE_WARN],
    'all passing' => [[PostureSignal::pass('a', 'ok'), PostureSignal::unmeasured('b', 'empty')], 'Pass', SummaryFigureData::TONE_ACTIVE],
    'only unmeasured' => [[PostureSignal::unmeasured('a', 'empty')], 'Unmeasured', null],
    'no signals' => [[], 'Unmeasured', null],
]);

it('counts suspended tenants, failed provisioning and failing checks as open risks', function () {
    $figures = (new RiskCard)->measure(new InMemoryBoardSource(
        [
            riskTenant('acme'),
            riskTenant('globex', suspended: true),
            riskTenant('initech', status: 'failed'),
            riskTenant('demo', suspended: true, synthetic: true),
            riskTenant('hooli', status: 'provisioning'),
        ],
        posture: [PostureSignal::fail('a', 'broken'), PostureSignal::warn('b', 'drift'), PostureSignal::pass('c', 'ok')],
    ));

    expect(figureRows($figures))->toBe([
        ['open', 4, SummaryFigureData::TONE_DANGER],
        ['suspended', 2, SummaryFigureData::TONE_DANGER],
        ['failed-provisioning', 1, SummaryFigureData::TONE_DANGER],
        ['failing-checks', 1, SummaryFigureData::TONE_DANGER],
    ]);
});

it('reads no open risk as zero, in the calm tone', function () {
    $figures = (new RiskCard)->measure(new InMemoryBoardSource([riskTenant('acme')], posture: [PostureSignal::warn('b', 'drift')]));

    expect(figureRows($figures))->toBe([
        ['open', 0, SummaryFigureData::TONE_ACTIVE],
        ['suspended', 0, null],
        ['failed-provisioning', 0, null],
        ['failing-checks', 0, null],
    ]);
});

it('draws the posture card\'s dashboard summary from the bound source', function () {
    app()->instance(BoardSource::class, new InMemoryBoardSource(posture: [PostureSignal::warn('a', 'drift')]));

    $definition = app(ParticleResourceRegistry::class)->definition('exec-posture', 'exec');
    $summary = app(BoardCardSummaryProvider::class)->summary($definition);

    expect($summary->key)->toBe('exec-posture')
        ->and($summary->icon)->toBe('ShieldCheck')
        ->and(array_map(fn (SummaryFigureData $f): array => [$f->key, $f->value], $summary->figures))->toBe([
            ['status', 'Warn'], ['passing', 0], ['warnings', 1], ['failing', 0], ['unmeasured', 0],
        ]);
});

it('adds the age of the oldest stored reading, relative to the source\'s as-of moment, and nothing when every reading is current', function () {
    $asOf = CarbonImmutable::parse('2026-08-20 12:00:00');
    $stored = (new PostureCard)->measure(new InMemoryBoardSource(asOf: $asOf, posture: [
        PostureSignal::pass('a', 'ok')->measured($asOf->subMinutes(12)),
        PostureSignal::warn('b', 'drift')->measured($asOf->subMinutes(40)),
        PostureSignal::fail('c', 'broken')->from('ledger'),
    ]));
    $current = (new PostureCard)->measure(new InMemoryBoardSource(posture: [PostureSignal::pass('a', 'ok')]));

    expect(end($stored)->id)->toBe('measured')
        ->and(end($stored)->value)->toBe('40 minutes ago')
        ->and(end($stored)->label)->toBe('Measured')
        ->and(array_column(figureRows($current), 0))->toBe(['status', 'passing', 'warnings', 'failing', 'unmeasured']);
});

it('reads failing checks as unmeasured, and the headline without the calm tone, when no posture signal measured anything', function (array $posture) {
    $figures = (new RiskCard)->measure(new InMemoryBoardSource([riskTenant('acme')], posture: $posture));

    expect(figureRows($figures))->toBe([
        ['open', 0, null],
        ['suspended', 0, null],
        ['failed-provisioning', 0, null],
        ['failing-checks', 'Unmeasured', null],
    ]);
})->with([
    'no report yet' => [[PostureSignal::unmeasured('doctor.unmeasured', 'not yet')]],
    'no signals' => [[]],
]);
