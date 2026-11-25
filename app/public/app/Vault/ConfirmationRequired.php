<?php

namespace App\Vault;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A sensitive action was tried without a fresh password confirmation. Still a 403; web requests get the confirm page. */
final class ConfirmationRequired extends HttpException
{
    public function __construct()
    {
        parent::__construct(403);
    }
}
