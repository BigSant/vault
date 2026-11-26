<?php

namespace App\Vault;

/** Onetime Secret did not do what was asked. $status is its HTTP status, 0 when it could not be reached. */
final class OneTimeSecretError extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Onetime Secret request failed');
    }

    /** One sentence for people; never the service's own message, which could echo what was sent. */
    public function reason(): string
    {
        return match (true) {
            $this->status === 0 => 'Onetime Secret could not be reached. Try again later.',
            in_array($this->status, [401, 403], true) => 'Onetime Secret did not accept the account email and API token. An owner can check them in Setup.',
            $this->status === 429 => 'Onetime Secret is limiting requests. Try again in a minute.',
            in_array($this->status, [400, 422], true) => 'Onetime Secret refused the link. The lifetime may be longer than the account allows, or the content too long.',
            default => 'Onetime Secret did not answer as expected. Try again later.',
        };
    }
}
