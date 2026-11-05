<?php

namespace Splicewire\Beam\Exec\Posture;

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
use Splicewire\Beam\Exec\Posture\Contributors\DoctorPostureContributor;

/**
 * Where the board's posture comes from: each entry is the class-string of a {@see PostureContributor}, keyed by a
 * short kebab-case name (`doctor`, `determination`), resolved from the container when the board reads posture.
 *
 * A host fills it from `config('beam.exec.posture')`, a map of key to contributor class, whose default is the
 * doctor-backed {@see DoctorPostureContributor}. A package contributes from its own provider by resolving this
 * registry and calling {@see register()}; the dependency points from the contributor's package down to exec, never
 * the other way. A host that declares its own config map replaces the default, so it names `doctor` too to keep it.
 *
 * Duplicate keys are rejected: two contributors under one key would silently drop one source of posture from the
 * board. A contributor that wants to stand in for another is configured in its place.
 */
#[IsRegistry(
    root: 'beam.exec.posture',
    entryType: 'class-string<'.PostureContributor::class.'>',
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'the board\'s posture contributors, keyed by name; each is a PostureContributor whose pass/warn/fail signals the posture and risk cards count',
)]
class PostureRegistry implements Gated, Registry
{
    private ?BasicRegistry $store = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<PostureContributor>|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string) $key) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The posture contributor key `%s` must be kebab-case (`doctor`, `determination`).',
                (string) $key,
            ));
        }

        if (! is_string($entry) || ! is_subclass_of($entry, PostureContributor::class)) {
            throw new InvalidArgumentException(sprintf(
                'The posture contributor `%s` must be the class-string of a %s; got %s.',
                (string) $key,
                PostureContributor::class,
                is_string($entry) ? '`'.$entry.'`' : get_debug_type($entry),
            ));
        }

        $this->store()->register($key, $entry, $by, $ability);

        return $this;
    }

    /** @return list<string> every contributor key, in registration order */
    public function contributorKeys(): array
    {
        return $this->store()->relativeKeys();
    }

    /** The contributor registered under `$key`, resolved from the container. */
    public function contributor(string $key): PostureContributor
    {
        return $this->container->make($this->resolve($key));
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

    /** The registrant that wrote `$key`, such as `config:beam.exec.posture` or `splicewire/tower`. */
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
