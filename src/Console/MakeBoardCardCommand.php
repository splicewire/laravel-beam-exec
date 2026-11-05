<?php

namespace Splicewire\Beam\Exec\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Board\BoardCardDefinition;
use Splicewire\Beam\Exec\Board\BoardMetric;
use Symfony\Component\Console\Input\InputOption;

/**
 * `splicewire:beam:exec:make-card` — mints a board card: a {@see BoardMetric} declared with {@see BoardCard}, whose
 * one figure reads through the source, and prints the config line that puts it on the board.
 *
 * The card's key defaults to the class name in kebab-case without a `Card` suffix (`RevenueCard` → `revenue`), its
 * label to that key in title case. The key is checked by the same rule the registry applies, so the generator
 * cannot mint a card the registry would refuse.
 */
class MakeBoardCardCommand extends GeneratorCommand
{
    protected $name = 'splicewire:beam:exec:make-card';

    protected $description = 'Scaffold a board card (a BoardMetric declared with #[BoardCard]) for the exec realm.';

    protected $type = 'Board card';

    protected function getStub(): string
    {
        return dirname(__DIR__, 2).'/stubs/board-card.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Board\Cards';
    }

    public function handle(): int
    {
        $key = $this->cardKey();

        if (! BoardCardDefinition::isValidKey($key)) {
            $this->components->error("The card key `{$key}` is not kebab-case, or is the reserved `dashboard`. Pass --key=.");

            return self::FAILURE;
        }

        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $class = $this->qualifyClass($this->getNameInput());

        $this->components->info(sprintf(
            'Add it to config/beam/exec.php to draw it as `%s%s`:',
            BoardCardDefinition::RESOURCE_PREFIX,
            $key,
        ));
        $this->line("    'cards' => [..., \\{$class}::class],");

        return self::SUCCESS;
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $key = $this->cardKey();
        $icon = $this->option('icon');
        $order = $this->option('order');

        return str_replace(
            ['{{ key }}', '{{ label }}', '{{ icon }}', '{{ order }}'],
            [
                $key,
                str_replace("'", "\\'", (string) ($this->option('label') ?: Str::headline($key))),
                is_string($icon) && $icon !== '' ? "'".str_replace("'", "\\'", $icon)."'" : 'null',
                is_numeric($order) ? (string) (int) $order : 'null',
            ],
            $stub,
        );
    }

    private function cardKey(): string
    {
        $key = $this->option('key');

        if (is_string($key) && $key !== '') {
            return $key;
        }

        return Str::kebab(Str::beforeLast(class_basename($this->getNameInput()), 'Card') ?: class_basename($this->getNameInput()));
    }

    protected function getOptions(): array
    {
        return [
            ['key', null, InputOption::VALUE_REQUIRED, 'The card key (kebab-case); its resource is exec-{key}'],
            ['label', null, InputOption::VALUE_REQUIRED, 'The card title'],
            ['icon', null, InputOption::VALUE_REQUIRED, 'A Lucide icon name'],
            ['order', null, InputOption::VALUE_REQUIRED, 'The card\'s place on the board, ascending'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the card if it already exists'],
        ];
    }
}
