<?php

namespace App\Providers;

use App\Vault\Access;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Validator as FormValidator;
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
        // Activity rows about a request say which action was asked for: request id => action, for the rows on show.
        View::composer('activity', function (Page $view) {
            $events = $view->getData()['events'] ?? [];
            $ids = collect($events instanceof \Illuminate\Contracts\Pagination\Paginator ? $events->items() : $events)
                ->filter(fn ($event) => ($event->event ?? null) === 'operation.request' && is_string($event->subject ?? null))->pluck('subject')->unique();
            $view->with('requestActions', $ids->isEmpty() ? [] : DB::table('operations')->whereIn('id', $ids->all())->pluck('action', 'id')->all());
        });
        // Validation messages name fields by their visible labels (lang/en/validation.php). A label that differs on
        // one form ("Initial password" for a new member) is listed there under form_attributes, by request path.
        Validator::resolver(function ($translator, array $data, array $rules, array $messages, array $attributes) {
            $forms = $translator->get('validation.form_attributes');
            foreach (is_array($forms) ? $forms : [] as $pattern => $labels) {
                if (request()->is($pattern)) {
                    $attributes += $labels;
                }
            }

            return new FormValidator($translator, $data, $rules, $messages, $attributes);
        });
    }

    /**
     * The workspace a request is about, for pages that render without a controller's $space (the confirm page shown
     * in place of a blocked POST): the route's workspace, else the workspace of the route's entry, else null.
     * Only an id: the header composer checks membership before it shows anything.
     */
    public static function requestWorkspace(Request $r): ?string
    {
        try {
            $workspace = $r->route('workspace');
            if (is_string($workspace) && $workspace !== '') {
                return $workspace;
            }
            $entry = $r->is('entries/*') ? $r->route('id') : null;
            if (is_string($entry) && $entry !== '') {
                return DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')
                    ->join('projects', 'projects.id', '=', 'environments.project_id')->where('entries.id', $entry)->value('projects.workspace_id');
            }
        } catch (\Throwable) {
            // No route or no database: the header falls back to the last workspace.
        }

        return null;
    }

    /** Agent requests in a workspace that wait for a decision or an MFA code and have not expired. */
    public static function waiting(string $workspace): int
    {
        return DB::table('operations')->join('machines', 'machines.id', '=', 'operations.machine_id')->where('machines.workspace_id', $workspace)
            ->whereIn('operations.state', ['pending', 'requires_user_action'])->where('operations.expires_at', '>', now())->count();
    }

    /**
     * Where the page head ends in a rendered page: after the first h1, the `.head` block around it, and the tabs or
     * jump links right below. The layout puts the error summary there when script cannot move it to its form.
     */
    public static function headEnd(string $html): int
    {
        $close = strpos($html, '</h1>');
        if ($close === false) {
            return 0;
        }
        $end = $close + 5;
        $head = strrpos(substr($html, 0, $end), '<div class="head"');
        if ($head !== false && preg_match_all('#<div\b|</div>#', $html, $tags, PREG_OFFSET_CAPTURE, $head)) {
            $depth = 0;
            foreach ($tags[0] as [$tag, $at]) {
                $depth += $tag === '</div>' ? -1 : 1;
                if ($depth === 0) {
                    $end = max($end, $at + 6);
                    break;
                }
            }
        }
        while (preg_match('#\G\s*(?:<!--.*?-->\s*)*<nav class="(?:tabs|subnav)"[^>]*>.*?</nav>#s', $html, $m, 0, $end)) {
            $end += strlen($m[0]);
        }

        return $end;
    }

    /**
     * Header data for signed-in pages.
     *   $navSpaces  the user's workspaces as rows {id, name, role}, ordered by name
     *   $navSpaceId the workspace the header shows: the page's $space, else the last one opened in this session;
     *               a page that sets $navFirst (the confirm page) falls back to the first workspace instead of none
     *   $navRole    the user's role in that workspace ('owner' | 'editor' | 'viewer'), or null without one
     *   $navWaiting agent requests waiting for a decision or an MFA code in it; 0 unless the role is owner
     * Each query runs at most once per request, however many views ask.
     */
    private function navigation(Page $view): void
    {
        $request = request();
        // Sign-in pages and bare error pages have no header, and must render even when the database is down.
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
            if ($id === null && ! empty($view->getData()['navFirst'])) {
                $id = $spaces->first()?->id;
            }
        }
        $role = $id === null ? null : $spaces->firstWhere('id', $id)?->role;
        $waiting = 0;
        if ($role === 'owner') {
            $waiting = $request->attributes->get('nav.waiting.'.$id);
            if ($waiting === null) {
                $waiting = self::waiting($id);
                $request->attributes->set('nav.waiting.'.$id, $waiting);
            }
        }
        // Remembered so the confirm page and a failed form can send people back (an error page or the confirm page itself is no place to return to); browsers send no Referer here (Referrer-Policy: no-referrer).
        if ($view->name() === 'layout' && $request->isMethod('GET') && $session && empty($view->getData()['shell']) && ! $request->is('confirm')) {
            $session->put('last_page', $request->getRequestUri());
        }
        $view->with(['navSpaces' => $spaces, 'navSpaceId' => $id, 'navRole' => $role, 'navWaiting' => $waiting]);
    }
}
