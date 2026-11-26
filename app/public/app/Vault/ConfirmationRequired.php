<?php

namespace App\Vault;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A sensitive action was tried without a fresh password confirmation. Still a 403; web requests get the confirm page.
 * $failed: the request carried a password for it and that password was wrong.
 */
final class ConfirmationRequired extends HttpException
{
    public function __construct(public readonly bool $failed = false)
    {
        parent::__construct(403);
    }
}
