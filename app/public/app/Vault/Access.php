<?php

namespace App\Vault;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class Access
{
    /** Days before its end from which an active agent or grant reads "expires soon". */
    public const SOON_DAYS = 3;

    /** Still active (not revoked, not past its end) and ending within SOON_DAYS: the end-date chip and the row stripe. */
    public static function endsSoon(mixed $revoked, mixed $ends): bool
    {
        $at = strtotime((string) $ends);

        return ! $revoked && $at !== false && $at > time() && $at <= time() + self::SOON_DAYS * 86400;
    }

    public function workspace(string $id, array $roles = ['owner', 'editor', 'viewer']): object
    {
        $row = DB::table('members')->join('workspaces', 'workspaces.id', '=', 'members.workspace_id')
            ->where('workspace_id', $id)->where('user_id', Auth::id())->select('workspaces.*', 'members.role')->first();
        abort_unless($row && in_array($row->role, $roles, true), 404);

        return $row;
    }

    public function environment(string $id, array $roles = ['owner', 'editor']): object
    {
        $row = DB::table('environments')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->workspace($row->workspace_id, $roles);

        return $row;
    }

    public function project(string $id, array $roles = ['owner', 'editor']): object
    {
        $row = DB::table('projects')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->workspace($row->workspace_id, $roles);

        return $row;
    }

    public function entry(string $id, array $roles = ['owner', 'editor', 'viewer']): object
    {
        $row = DB::table('entries')->where('id', $id)->first();
        abort_unless($row, 404);
        $row->workspace_id = $this->project($row->project_id, $roles)->workspace_id;

        return $row;
    }

    /** How long a password confirmation allows sensitive actions, in seconds. */
    public const CONFIRM_SECONDS = 300;

    /** Password checks per user and minute, shared by every form that carries `confirm_password`. */
    private const CONFIRM_ATTEMPTS = 10;

    /**
     * Lets a sensitive request through only with a fresh password confirmation.
     * A stale session may confirm in the same POST with `confirm_password`; a correct one refreshes the confirmation.
     */
    public function recent(Request $r): void
    {
        if (self::confirmedUntil($r) !== null) {
            return;
        }
        // Body only: a password must never travel in a query string.
        if (! $r->isMethod('POST') || ! $r->request->has('confirm_password')) {
            throw new ConfirmationRequired;
        }
        $password = $r->request->all()['confirm_password'];
        $key = 'confirm-password:'.$r->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, self::CONFIRM_ATTEMPTS), 429);
        RateLimiter::hit($key, 60);
        if (! is_string($password) || $password === '' || strlen($password) > 200 || ! Hash::check($password, $r->user()->password)) {
            throw ValidationException::withMessages(['confirm_password' => 'Current password is not right.']);
        }
        $r->session()->put('confirmed_at', time());
    }

    /** Unix time until which this session may do sensitive actions, or null when it must confirm first. */
    public static function confirmedUntil(Request $r): ?int
    {
        if (! $r->hasSession()) {
            return null;
        }
        $until = (int) $r->session()->get('confirmed_at', 0) + self::CONFIRM_SECONDS;

        return $until > time() ? $until : null;
    }

    public function audit(?string $workspace, string $event, ?string $subject = null, string $outcome = 'ok', ?string $actor = null): void
    {
        DB::table('audit')->insert(['workspace_id' => $workspace, 'actor' => $actor ?? 'user:'.Auth::id(),
            'event' => $event, 'subject' => $subject, 'outcome' => $outcome, 'created_at' => now()]);
    }
}
