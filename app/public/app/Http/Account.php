<?php

namespace App\Http;

use App\Providers\AppServiceProvider;
use App\Vault\Access;
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
        return view('confirm', ['return' => self::safePath($r->query('return')), 'blocked' => false, 'failed' => false, 'navFirst' => true]);
    }

    /**
     * The header's waiting count for an owner, as {"waiting": N}: app.js asks once a minute on the pages that do not
     * refresh their requests themselves. Anyone else gets 404, like the Agents pages.
     */
    public function waiting(Access $access, string $workspace)
    {
        $access->workspace($workspace, ['owner']);

        return response()->json(['waiting' => AppServiceProvider::waiting($workspace)]);
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

    public static function errorReturnPath(Request $r): ?string
    {
        $origin = $r->getSchemeAndHttpHost();
        $referer = $r->headers->get('referer', '');
        $paths = [
            str_starts_with($referer, $origin.'/') ? substr($referer, strlen($origin)) : null,
            $r->session()->get('last_page'),
        ];
        foreach ($paths as $path) {
            if (! is_string($path) || self::safePath($path) !== $path) {
                continue;
            }
            try {
                $route = app('router')->getRoutes()->match(Request::create($origin.$path, 'GET'));
                if (! $route->isFallback && in_array('vault.auth', $route->middleware(), true)) {
                    return $path;
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                // Unknown and POST-only addresses cannot serve as recovery pages.
            }
        }
        return null;
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
