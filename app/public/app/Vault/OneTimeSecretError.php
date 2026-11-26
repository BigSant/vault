<?php

namespace App\Vault;

/** Onetime Secret did not do what was asked. $status is its HTTP status, 0 when it could not be reached. */
final class OneTimeSecretError extends \RuntimeException
{
    /** $type: the service's machine-readable error class (error_type), e.g. "EntitlementRequired". */
    public function __construct(public readonly int $status, public readonly ?string $type = null)
    {
        parent::__construct('Onetime Secret request failed');
    }

    /** The account's plan does not allow what was asked (a lifetime beyond it, usually): the request, not the account, is wrong. */
    public function entitlement(): bool
    {
        return $this->type !== null && (str_contains($this->type, 'Entitlement') || str_contains($this->type, 'Plan'));
    }

    /** One sentence for people; never the service's own message, which could echo what was sent. */
    public function reason(bool $creating = false): string
    {
        return match (true) {
            $this->entitlement() => 'The Onetime Secret plan of this account does not allow that.'.($creating ? ' Choose a shorter lifetime.' : ''),
            $this->status === -1 => 'Vault cannot check this link: it was made in an earlier Onetime Secret account, or its receipt is gone.',
            $this->status === 0 => 'Onetime Secret could not be reached. Try again later.',
            in_array($this->status, [401, 403], true) => 'Onetime Secret did not accept the account email and API token. An owner can check them in Setup.',
            $this->status === 429 => 'Onetime Secret is limiting requests. Try again in a minute.',
            in_array($this->status, [400, 422], true) => 'Onetime Secret refused the link. The lifetime may be longer than the account allows, or the content too long.',
            default => 'Onetime Secret did not answer as expected. Try again later.',
        };
    }
}
