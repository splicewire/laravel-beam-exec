<?php

use Illuminate\Support\Facades\File;
use Splicewire\Beam\Exec\Board\BoardCardDefinition;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Splicewire\Beam\Exec\Data\BoardFigureData;
use Splicewire\Beam\Exec\Tests\Support\InMemoryBoardSource;

beforeEach(function () {
    $this->cards = app_path('Board/Cards');
    File::deleteDirectory($this->cards);
});

afterEach(function () {
    File::deleteDirectory($this->cards);
});

it('mints a card the registry accepts, with the key derived from the class name, and prints its config line', function () {
    $this->artisan('splicewire:beam:exec:make-card', ['name' => 'RevenueMixCard', '--icon' => 'PieChart', '--order' => 30])
        ->expectsOutputToContain('exec-revenue-mix')
        ->expectsOutputToContain("'cards' => [..., \\App\\Board\\Cards\\RevenueMixCard::class],")
        ->assertSuccessful();

    $path = $this->cards.'/RevenueMixCard.php';
    $source = File::get($path);

    expect($source)->toContain("#[BoardCard(key: 'revenue-mix', label: 'Revenue Mix', icon: 'PieChart', order: 30)]")
        ->and($source)->toContain('namespace App\Board\Cards;');

    require_once $path;

    $definition = BoardCardDefinition::of('App\Board\Cards\RevenueMixCard');
    $figures = app($definition->class)->measure(new InMemoryBoardSource);

    expect($definition->resourceKey())->toBe('exec-revenue-mix')
        ->and(is_subclass_of($definition->class, BoardMetric::class))->toBeTrue()
        ->and($figures)->toHaveCount(1)
        ->and($figures[0])->toBeInstanceOf(BoardFigureData::class);
});

it('takes an explicit key and label, and writes null for an icon and order it was not given', function () {
    $this->artisan('splicewire:beam:exec:make-card', ['name' => 'Arr', '--key' => 'arr', '--label' => "Founders' ARR"])
        ->assertSuccessful();

    expect(File::get($this->cards.'/Arr.php'))
        ->toContain("#[BoardCard(key: 'arr', label: 'Founders\\' ARR', icon: null, order: null)]");
});

it('refuses a key the registry would refuse, and writes nothing', function () {
    $this->artisan('splicewire:beam:exec:make-card', ['name' => 'DashboardCard'])
        ->expectsOutputToContain('reserved `dashboard`')
        ->assertFailed();

    $this->artisan('splicewire:beam:exec:make-card', ['name' => 'Odd', '--key' => 'Not_Kebab'])
        ->assertFailed();

    expect(File::exists($this->cards))->toBeFalse();
});
