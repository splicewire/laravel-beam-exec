<?php

use Splicewire\Beam\Localization\Ratchet\TextRatchet;

it('has no remaining imperative literal text or unreviewed exclusions', function () {
    if (getenv('EXEC25_PHASE') === 'baseline') {
        $this->markTestSkipped('Candidate zero ratchet follows the unchanged original baseline.');
    }
    $root = dirname(__DIR__, 2);
    expect(TextRatchet::measure($root))->toBe(['total' => 0, 'files' => []])
        ->and(TextRatchet::excluded($root))->toBe([])
        ->and(TextRatchet::compare($root))->toBe([
            'rose' => [], 'fell' => [], 'missing' => false, 'stale' => [], 'invalid' => [],
        ]);
});
