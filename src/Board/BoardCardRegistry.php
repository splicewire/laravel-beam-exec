<?php

namespace Splicewire\Beam\Exec\Board;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;

/**
 * The board's cards, keyed by card key: each entry is the class-string of a {@see BoardMetric} declared with
 * {@see BoardCard}, resolved from the container when the card is drawn.
 *
 * A host fills it from `config('beam.exec.cards')`, a list of card classes, or from its own provider's
 * `register()` with {@see add()}. The key is read off the class's attribute, so a card is named in one place.
 * The package's default config lists the built-in cards; a host that declares its own list replaces it.
 *
 * Each card becomes a particle resource in the `exec` realm at the exec provider's boot. A card added from a
 * host provider's own `boot()` still reaches the registry and the dashboard, but a route file reading the realm's
 * hrefs at load time may not see it; add cards in `register()` or in config.
 *
 * Duplicate keys are rejected: a card key names a resource and a URL, and a second card under one key would
 * silently replace a figure the board shows.
 */
#[IsRegistry(
    root: 'beam.exec.cards',
    entryType: 'class-string<'.BoardMetric::class.'>',
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'the board\'s cards, keyed by card key; each is a BoardMetric class declared with #[BoardCard], drawn as an exec-{key} resource',
)]
class BoardCardRegistry implements Gated, Registry
{
    private ?BasicRegistry $store = null;

    public function __construct(private readonly Container $container) {}

    /**
     * Add a card class under the key its {@see BoardCard} attribute declares.
     *
     * @param  class-string<BoardMetric>  $class
     */
    public function add(string $class, ?string $by = null): static
    {
        return $this->register(BoardCardDefinition::of($class)->key(), $class, $by);
    }

    /**
     * @param  class-string<BoardMetric>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if (! is_string($entry)) {
            throw new InvalidArgumentException(sprintf(
                'The board card `%s` must be the class-string of a %s; got %s.',
                (string) $key,
                BoardMetric::class,
                get_debug_type($entry),
            ));
        }

        $definition = BoardCardDefinition::of($entry);

        if ($definition->key() !== (string) $key) {
            throw new InvalidArgumentException(sprintf(
                'The board card `%s` is registered as `%s`, but its #[BoardCard] declares `%s`. Register it with add().',
                $entry,
                (string) $key,
                $definition->key(),
            ));
        }

        $this->store()->register($key, $entry, $by, $ability);

        return $this;
    }

    /** @return list<string> every card key, in registration order */
    public function cardKeys(): array
    {
        return $this->store()->relativeKeys();
    }

    /** @return list<BoardCardDefinition> every card, in registration order */
    public function definitions(): array
    {
        return array_map(
            fn (string $key): BoardCardDefinition => BoardCardDefinition::of($this->resolve($key)),
            $this->cardKeys(),
        );
    }

    /** The card drawn as resource `$resourceKey`, or null when no card is. */
    public function forResource(string $resourceKey): ?BoardCardDefinition
    {
        if (! str_starts_with($resourceKey, BoardCardDefinition::RESOURCE_PREFIX)) {
            return null;
        }

        $class = $this->tryResolve(substr($resourceKey, strlen(BoardCardDefinition::RESOURCE_PREFIX)));

        return is_string($class) ? BoardCardDefinition::of($class) : null;
    }

    /** The card's metric, resolved from the container. */
    public function metric(BoardCardDefinition $definition): BoardMetric
    {
        return $this->container->make($definition->class);
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store()->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store()->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store()->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->store()->matches($key);
    }

    public function keys(): array
    {
        return $this->store()->keys();
    }

    /** The registrant that wrote `$key`, such as `config:beam.exec.cards`. */
    public function registrantOf(RegistryKey|string $key): ?string
    {
        return $this->store()->registrantOf($key);
    }

    public function unfiltered(): Registry
    {
        return $this->store()->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->store()->authorizeWith($authorizer);

        return $this;
    }

    private function store(): BasicRegistry
    {
        return $this->store ??= BasicRegistry::for($this);
    }
}
