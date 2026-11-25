<?php

namespace App\Http;

use Illuminate\Http\Request;

/** Personal pages: the account, the password confirmation, and creating a workspace. */
final class Account
{
    /** $confirmedUntil comes from the view composer, like on every page. */
    public function show(Request $r)
    {
        return view('account', ['user' => $r->user()]);
    }

    public function confirmForm(Request $r)
    {
        return view('confirm', ['return' => self::safePath($r->query('return')), 'blocked' => false, 'failed' => false]);
    }

    public function newWorkspace()
    {
        return view('workspace-new');
    }

    /**
     * A path on this site that is safe to redirect to, or '/'.
     * Only a relative path with one leading slash passes: no scheme, no host, no backslash, no control characters.
     */
    public static function safePath(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 2000 || ! preg_match('#^/[^\\\\\x00-\x20\x7f]*$#D', $value) || str_contains($value, '//')) {
            return '/';
        }
        $path = strtok($value, '?#');

        // The confirm page itself and POST-only addresses are no place to come back to.
        return in_array($path, ['/confirm', '/logout', '/login', '/mfa'], true) ? '/' : $value;
    }

    /** Where a blocked sensitive request came from: the same-origin Referer, else the last page shown in this session. */
    public static function returnPath(Request $r): string
    {
        $referer = $r->headers->get('referer');
        if (is_string($referer) && str_starts_with($referer, $r->getSchemeAndHttpHost().'/')) {
            return self::safePath(substr($referer, strlen($r->getSchemeAndHttpHost())));
        }

        return self::safePath($r->hasSession() ? $r->session()->get('last_page') : null);
    }
}
