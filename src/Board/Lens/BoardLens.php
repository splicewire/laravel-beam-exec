<?php

namespace Splicewire\Beam\Exec\Board\Lens;

use Splicewire\Beam\Exec\Board\BoardSource;

/**
 * The board's lens for one request: which {@see BoardSource} its cards read.
 *
 * It is chosen per request, by {@see BoardLensResolver}, from the `beam_exec_lens` cookie. The cookie is encrypted
 * the way every Laravel cookie on a `web` route is (the host's `EncryptCookies`), the same shape beam-ux's Preview
 * "view as" cookie has, and its value is checked against a closed vocabulary. A missing, unreadable or unknown value
 * is not an error: it reads as the default lens ({@see $chosen} false).
 *
 * The cookie value is the mode, and for a scenario the portfolio id after a colon: `live`, `truth`, `scenario`, or
 * `scenario:demo-mixed-book`. A bare `scenario` reads the host's default portfolio.
 */
final class BoardLens
{
    /** The cookie the lens is read from. */
    public const COOKIE = 'beam_exec_lens';

    /** How long a chosen lens is kept, in minutes (eight hours, a board meeting's day). */
    public const COOKIE_MINUTES = 480;

    /**
     * @param  string|null  $portfolio  the scenario portfolio id; set only for {@see BoardLensMode::Scenario}
     * @param  bool  $chosen  whether a valid cookie chose this lens (false = the default)
     */
    public function __construct(
        public readonly BoardLensMode $mode,
        public readonly ?string $portfolio = null,
        public readonly bool $chosen = false,
    ) {}

    public static function live(bool $chosen = false): self
    {
        return new self(BoardLensMode::Live, chosen: $chosen);
    }

    public static function truth(bool $chosen = false): self
    {
        return new self(BoardLensMode::Truth, chosen: $chosen);
    }

    public static function scenario(?string $portfolio = null, bool $chosen = false): self
    {
        return new self(BoardLensMode::Scenario, $portfolio, $chosen);
    }

    /**
     * Read a cookie value. Null for anything outside the vocabulary: an empty value, an unknown mode, a portfolio
     * on a mode that takes none, or a portfolio id that is not kebab-case.
     */
    public static function parse(mixed $value): ?self
    {
        if (! is_string($value) || $value === '' || strlen($value) > 200) {
            return null;
        }

        [$mode, $portfolio] = array_pad(explode(':', $value, 2), 2, null);
        $mode = BoardLensMode::tryFrom($mode);

        if ($mode === null) {
            return null;
        }

        if ($portfolio !== null && ($mode !== BoardLensMode::Scenario || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $portfolio) !== 1)) {
            return null;
        }

        return new self($mode, $portfolio, chosen: true);
    }

    /** The cookie value that chooses this lens. */
    public function value(): string
    {
        return $this->portfolio === null ? $this->mode->value : "{$this->mode->value}:{$this->portfolio}";
    }

    public function synthesizes(): bool
    {
        return $this->mode->synthesizes();
    }
}
