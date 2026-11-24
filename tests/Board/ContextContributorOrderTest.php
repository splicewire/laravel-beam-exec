<?php

use Illuminate\Support\Facades\Artisan;
use Schemastud\Frame\Contracts\ResourceContextContributor;
use Schemastud\Frame\Registry\ContextBlockFacts;
use Schemastud\Frame\Registry\ContextContributorRegistry;
use Schemastud\Frame\Registry\ContextManifest;
use Splicewire\Beam\Exec\Board\BoardCardContextNodes;
use Splicewire\Beam\Exec\Data\BoardFigureData;

/*
 * frame-foundation 93, decision 87's merge-order table row 13: the estate order on a beam-exec host. beam registers
 * `beam.contributions` then `beam.collections` from its boot; beam-exec registers `beam-exec.board-cards` from its boot
 * link, after beam (provider boot order). So board cards › collections › contributions on a shared context, and the
 * resource's own declaration › all.
 */

it('lists the three contributors in answer order, each with its registrant (popcorn:keys)', function (): void {
    $exit = Artisan::call('popcorn:keys', ['prefix' => 'frame.context-contributors', '--json' => true]);
    $read = json_decode(Artisan::output(), true);
    $root = ContextContributorRegistry::ROOT;

    expect($exit)->toBe(0)
        ->and($read['keys'])->toBe(["{$root}.beam.contributions", "{$root}.beam.collections", "{$root}.beam-exec.board-cards"])
        ->and(array_values($read['registrants']))->toBe(['splicewire/laravel-beam', 'splicewire/laravel-beam', 'splicewire/laravel-beam-exec']);
});

it('answers a card\'s root context from the board-card contributor, after beam\'s two', function (): void {
    $factory = app(ContextContributorRegistry::class)->unfiltered()->resolve(ContextContributorRegistry::ROOT.'.beam-exec.board-cards');

    expect($factory())->toBeInstanceOf(BoardCardContextNodes::class)
        ->and(app(ContextManifest::class)->forResource(BoardFigureData::class, new ContextBlockFacts(key: 'exec-mrr-trend'))['byNode']['']['summary']['widget'] ?? null)->toBe('trend-card');
});

it('lets a later position win a shared root context: a host superseding beam.contributions keeps its slot', function (): void {
    $registry = app(ContextContributorRegistry::class);
    $earlier = new class implements ResourceContextContributor
    {
        public function nodesFor(string $key): array
        {
            return $key === 'exec-mrr-trend' ? ['' => ['summary' => ['participates' => true, 'widget' => 'host-card']]] : [];
        }
    };
    $registry->register('beam.contributions', static fn (): ResourceContextContributor => $earlier, by: 'host/app');

    $root = app(ContextManifest::class)->forResource(BoardFigureData::class, new ContextBlockFacts(key: 'exec-mrr-trend'))['byNode'][''];
    $keys = array_map(strval(...), $registry->unfiltered()->keys());

    expect($root['summary']['widget'])->toBe('trend-card')   // board cards, a later position, win the shared context
        ->and($keys[0])->toBe(ContextContributorRegistry::ROOT.'.beam.contributions')   // the replacement kept slot 1
        ->and($registry->registrantOf('beam.contributions'))->toBe('host/app');
});
