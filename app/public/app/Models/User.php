<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function credentialRevision(): string
    {
        return hash('sha256', $this->password);
    }

    protected $hidden = ['password', 'remember_token'];
}
