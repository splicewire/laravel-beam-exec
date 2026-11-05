<?php

namespace Splicewire\Beam\Exec\Board;

use InvalidArgumentException;
use ReflectionClass;
use Splicewire\Beam\Exec\Board\Attributes\BoardCard;
use Splicewire\Beam\Exec\Realm\ExecRealm;

/**
 * A board card's declaration, read off its class: the {@see BoardCard} attribute plus the class that measures it.
 */
final class BoardCardDefinition
{
    /** Every card resource's key starts with this, so a card key and a resource key cannot be confused. */
    public const RESOURCE_PREFIX = ExecRealm::KEY.'-';

    /** A card key may not take the realm dashboard's name: `exec-dashboard` is beam-ux's. */
    private const RESERVED = ['dashboard'];

    /**
     * @param  class-string<BoardMetric>  $class
     */
    private function __construct(
        public readonly string $class,
        public readonly BoardCard $card,
    ) {}

    /**
     * Read a card class's declaration, refusing a class that is not a {@see BoardMetric}, carries no
     * {@see BoardCard}, or names a key that is not kebab-case or is reserved.
     */
    public static function of(string $class): self
    {
        if (! class_exists($class) || ! is_subclass_of($class, BoardMetric::class)) {
            throw new InvalidArgumentException(sprintf(
                'The board card `%s` must be the class-string of a %s.',
                $class,
                BoardMetric::class,
            ));
        }

        $attributes = (new ReflectionClass($class))->getAttributes(BoardCard::class);

        if ($attributes === []) {
            throw new InvalidArgumentException(sprintf(
                'The board card `%s` carries no #[%s] attribute, so it has no key, label or order.',
                $class,
                BoardCard::class,
            ));
        }

        $card = $attributes[0]->newInstance();

        if (! self::isValidKey($card->key)) {
            throw new InvalidArgumentException(sprintf(
                'The board card `%s` declares the key `%s`. A card key is kebab-case and is not %s.',
                $class,
                $card->key,
                implode(', ', array_map(fn (string $k): string => "`{$k}`", self::RESERVED)),
            ));
        }

        return new self($class, $card);
    }

    /** Whether `$key` may name a card: kebab-case, and not reserved. */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key) === 1 && ! in_array($key, self::RESERVED, true);
    }

    public function key(): string
    {
        return $this->card->key;
    }

    /** The card's particle resource key, `exec-{key}`. */
    public function resourceKey(): string
    {
        return self::RESOURCE_PREFIX.$this->card->key;
    }

    /** The Gate ability that reads the card: its own, or the realm gate's. */
    public function ability(): string
    {
        return $this->card->ability ?? 'entitlement:'.ExecRealm::ENTITLEMENT;
    }
}
