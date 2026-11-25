<?php

namespace App\Providers;

use App\Vault\Access;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as Page;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        URL::forceScheme('https');
        RateLimiter::for('login', fn ($r) => Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('mfa', fn ($r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('sensitive', fn ($r) => Limit::perMinute(10)->by((string) ($r->user()?->id ?? $r->ip())));
        RateLimiter::for('api-ip', fn ($r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('machine', fn ($r) => Limit::perMinute(60)->by(hash('sha256', (string) $r->bearerToken())));
        View::composer(['layout', 'tabs', 'account', 'workspace-new'], fn (Page $view) => $this->navigation($view));
        // Unix time until which sensitive actions need no password, or null. Every form can then ask for it inline.
        View::composer('*', fn (Page $view) => $view->with('confirmedUntil', Access::confirmedUntil(request())));
    }

    /**
     * Header data for signed-in pages.
     *   $navSpaces  the user's workspaces as rows {id, name, role}, ordered by name
     *   $navSpaceId the workspace the header shows: the page's $space, else the last one opened in this session
     *   $navRole    the user's role in that workspace ('owner' | 'editor' | 'viewer'), or null without one
     *   $navWaiting agent requests waiting for a decision or an MFA code in it; 0 unless the role is owner
     * Each query runs at most once per request, however many views ask.
     */
    private function navigation(Page $view): void
    {
        $request = request();
        // Sign-in, error and confirm pages have no header, and must render even when the database is down.
        if ($view->name() === 'layout' && $view->getFactory()->hasSection('bare') || ! Auth::check()) {
            $view->with(['navSpaces' => collect(), 'navSpaceId' => null, 'navRole' => null, 'navWaiting' => 0]);

            return;
        }
        $spaces = $request->attributes->get('nav.spaces');
        if (! $spaces instanceof Collection) {
            $spaces = DB::table('workspaces')->join('members', 'members.workspace_id', '=', 'workspaces.id')->where('members.user_id', Auth::id())
                ->orderBy('workspaces.name')->get(['workspaces.id', 'workspaces.name', 'members.role']);
            $request->attributes->set('nav.spaces', $spaces);
        }
        $session = $request->hasSession() ? $request->session() : null;
        $id = $view->getData()['space']->id ?? null;
        if ($id !== null && $spaces->contains('id', $id)) {
            // Pages outside a workspace (account, new workspace) keep showing the one last opened.
            if ($view->name() === 'layout' && $session?->get('last_workspace') !== $id) {
                $session?->put('last_workspace', $id);
            }
        } else {
            $last = $session?->get('last_workspace');
            // Membership is checked again on every request: $spaces holds only the user's current workspaces.
            $id = is_string($last) && $spaces->contains('id', $last) ? $last : null;
        }
        $role = $id === null ? null : $spaces->firstWhere('id', $id)?->role;
        $waiting = 0;
        if ($role === 'owner') {
            $waiting = $request->attributes->get('nav.waiting.'.$id);
            if ($waiting === null) {
                $waiting = DB::table('operations')->join('machines', 'machines.id', '=', 'operations.machine_id')->where('machines.workspace_id', $id)
                    ->whereIn('operations.state', ['pending', 'requires_user_action'])->where('operations.expires_at', '>', now())->count();
                $request->attributes->set('nav.waiting.'.$id, $waiting);
            }
        }
        // Remembered so the confirm page can send people back; browsers send no Referer here (Referrer-Policy: no-referrer).
        if ($view->name() === 'layout' && $request->isMethod('GET') && $session) {
            $session->put('last_page', $request->getRequestUri());
        }
        $view->with(['navSpaces' => $spaces, 'navSpaceId' => $id, 'navRole' => $role, 'navWaiting' => $waiting]);
    }
}
