<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Account\Preferences;
use Meridian\Account\Session;
use Meridian\Account\User;

/**
 * Who is reading this request. Accounts are optional: an anonymous
 * viewer carries the default preferences and is a first-class case, not
 * a degraded one.
 */
final readonly class Viewer
{
    public function __construct(
        public Preferences $preferences,
        public bool $accountsEnabled,
        public ?User $user = null,
        public ?Session $session = null,
    ) {
    }

    public static function anonymous(bool $accountsEnabled, Preferences $preferences = new Preferences()): self
    {
        return new self($preferences, $accountsEnabled);
    }

    public function isSignedIn(): bool
    {
        return $this->user !== null && $this->session !== null;
    }

    public function csrfToken(): string
    {
        return $this->session?->csrfToken ?? '';
    }

    public function withPreferences(Preferences $preferences): self
    {
        return new self($preferences, $this->accountsEnabled, $this->user, $this->session);
    }
}
