<?php

use Splicewire\Beam\Schema\IdentifierLabels;

/*
 * app-walkthrough APP-09 at the owner: a class this package's operations make reachable from a particle declares its
 * rendered label (#[Title]), or is listed here. The list only shrinks. Hosts keep the same ratchet
 * (laravel-beam-commerce-starter's IdentifierLabelTest); exec-board ticket 24's seven Scenario*Data classes and
 * beam-synthetic's PortfolioMemberData reached it unlabeled because nothing here checked them.
 *
 * Scope: the classes IdentifierLabels finds from the booted registries (the instrument a host's ratchet and
 * `splicewire:beam:doctor` read). Judged: this package's classes, and beam-synthetic's, which reach a wire only
 * through exec's scenario operations. Other packages keep their own.
 */
const EXEC_IDENTIFIER_LABEL_RATCHET = [
    // The debt every host already lists with this package as owner (APP-09b).
    'Splicewire\Beam\Exec\Data\BoardFigureData',
    'Splicewire\Beam\Exec\Data\BoardLensBannerData',
    'Splicewire\Beam\Exec\Data\BoardLensBarData',
    'Splicewire\Beam\Exec\Data\BoardLensChoiceData',
    'Splicewire\Beam\Exec\Data\BoardLensOptionData',
];

it('labels every class its operations make reachable, or lists it', function () {
    $found = array_keys(array_filter(
        app(IdentifierLabels::class)->find(),
        fn (string $class): bool => str_starts_with($class, 'Splicewire\\Beam\\Exec\\')
            || str_starts_with($class, 'Splicewire\\Beam\\Synthetic\\'),
        ARRAY_FILTER_USE_KEY,
    ));

    expect(array_values(array_diff($found, EXEC_IDENTIFIER_LABEL_RATCHET)))->toBe([])
        ->and(array_values(array_diff(EXEC_IDENTIFIER_LABEL_RATCHET, $found)))->toBe([]);
});
