<?php

namespace App\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class OwnerSession
{
    public function handle(Request $r, Closure $next)
    {
        if (! Auth::check()) {
            return redirect('/login');
        }
        if (! hash_equals(Auth::user()->credentialRevision(), (string) $r->session()->get('auth_revision')) || ! $r->session()->get('mfa_at') || time() - (int) $r->session()->get('login_at', 0) > 28800) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect('/login');
        }

        return $next($r);
    }
}
