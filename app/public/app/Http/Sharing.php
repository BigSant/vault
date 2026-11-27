<?php

namespace App\Http;

use App\Vault\Access;
use App\Vault\EntryTypes;
use App\Vault\Links;
use App\Vault\OneTimeSecret;
use App\Vault\OneTimeSecretError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** One-time links in the web UI: the Links page (owner, editor), Setup › Onetime Secret and an agent's permission (owner). */
final class Sharing
{
    public function __construct(private Access $access, private Links $links, private EntryTypes $types) {}

    public function index(Request $r, string $workspace)
    {
        return $this->page($workspace, $r->query('entry'));
    }

    /** The Links page. $draft: what a form that failed held, shown again in this response only (never in the session). */
    private function page(string $workspace, mixed $entryId, array $draft = [], int $status = 200)
    {
        $space = $this->access->workspace($workspace, ['owner', 'editor']);
        $settings = $this->links->ready($workspace);
        $this->links->settle($workspace);
        $entry = null;
        $type = null;
        if (is_string($entryId) && Str::isUuid($entryId)) {
            $entry = $this->access->entry($entryId, ['owner', 'editor']);
            abort_unless($entry->workspace_id === $workspace, 404);
            $entry->place = DB::table('entries')->join('environments', 'environments.id', '=', 'entries.environment_id')->join('projects', 'projects.id', '=', 'entries.project_id')
                ->where('entries.id', $entry->id)->select('environments.name as environment', 'environments.production', 'projects.name as project')->first();
            $type = $this->types->find($workspace, $entry->type);
        }
        $links = DB::table('links')->where('links.workspace_id', $workspace)
            ->leftJoin('entries', 'entries.id', '=', 'links.entry_id')->leftJoin('machines', 'machines.id', '=', 'links.machine_id')->leftJoin('users', 'users.id', '=', 'links.user_id')
            ->select('links.*', 'entries.name as entry', 'machines.name as agent', 'users.name as person')
            ->orderByRaw("CASE WHEN links.state IN ('reserved', 'pending', 'sending', 'active', 'previewed') THEN 0 ELSE 1 END")->orderByDesc('links.created_at')->orderByDesc('links.id')
            ->paginate(50);

        return response()->view('links', ['space' => $space, 'settings' => $settings, 'account' => $settings ? $settings->region.'|'.$settings->email : null, 'entry' => $entry, 'type' => $type, 'links' => $links, 'draft' => $draft,
            'lifetimes' => $settings ? Links::lifetimes($settings) : [], 'canSetUp' => $space->role === 'owner'], $status)->header('Cache-Control', 'no-store');
    }

    public function store(Request $r, string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner', 'editor']);
        $input = $r->only(['entry_id', 'text', 'lifetime', 'passphrase', 'note']);
        try {
            // A wrong password comes back here too, with the draft.
            $this->access->recent($r);
            [$link, $url] = $this->links->createByUser($workspace, $r->user()->id, $input);
        } catch (ValidationException $e) {
            // The content may hold a secret: the form comes back in this response with it, not through a redirect
            // and the session. The passphrase is not refilled. session()->now() is dropped before the session is saved.
            $r->session()->now('form_errors', array_slice($e->errors(), 0, 8, true));
            $r->session()->now('error_form', $r->getPathInfo());
            $r->session()->now('error_unfilled', is_string($input['passphrase'] ?? null) && $input['passphrase'] !== '');

            return $this->page($workspace, $input['entry_id'] ?? null, ['text' => is_string($input['text'] ?? null) ? $input['text'] : null,
                'lifetime' => $input['lifetime'] ?? null, 'note' => is_string($input['note'] ?? null) ? $input['note'] : null], 422);
        }
        $entry = $link->entry_id ? DB::table('entries')->where('id', $link->entry_id)->first() : null;

        // The passphrase the person typed or generated is shown once beside the link, so a generated one is not lost.
        $passphrase = is_string($input['passphrase'] ?? null) && $input['passphrase'] !== '' ? $input['passphrase'] : null;

        return response()->view('link-created', ['space' => $space, 'link' => $link, 'url' => $url, 'entry' => $entry, 'passphrase' => $passphrase])->header('Cache-Control', 'no-store');
    }

    public function check(string $workspace)
    {
        $this->access->workspace($workspace, ['owner', 'editor']);
        [$checked, $missed] = $this->links->refresh($workspace);
        $elsewhere = $this->links->elsewhere($workspace);
        $notice = match (true) {
            $checked + $missed === 0 => 'No open links of the current account to check.',
            $missed === 0 => 'Checked '.$checked.' open '.Str::plural('link', $checked).' with Onetime Secret.',
            default => 'Checked '.$checked.' of '.($checked + $missed).' open links. Onetime Secret did not answer for the rest; their states are as last checked. Try again later.',
        }.($elsewhere ? ' '.$elsewhere.' open '.Str::plural('link', $elsewhere).' of an earlier account cannot be checked here; '.($elsewhere === 1 ? 'it ends' : 'they end').' with '.($elsewhere === 1 ? 'its' : 'their').' lifetime.' : '');

        return redirect('/workspaces/'.$workspace.'/links')->with('notice', $notice);
    }

    public function burn(Request $r, string $workspace, string $id)
    {
        $space = $this->access->workspace($workspace, ['owner', 'editor']);
        $link = DB::table('links')->where('id', $id)->where('workspace_id', $workspace)->first();
        abort_unless($link, 404);
        // An editor burns the links they made; an owner any link of the workspace, also an agent's.
        abort_unless($space->role === 'owner' || $link->user_id === $r->user()->id, 404);
        // A row that ended while the page was open (read, expired) says so instead of an error page.
        $already = in_array($link->state, Links::ENDED, true);
        try {
            $state = $already ? $link->state : $this->links->burn($link, 'user:'.$r->user()->id);
        } catch (ValidationException $e) {
            // Not a form: what went wrong is said like the other outcomes of Burn, above the list.
            return redirect('/workspaces/'.$workspace.'/links')->with('alert', collect($e->errors())->flatten()->first());
        } catch (HttpException $e) {
            abort_unless($e->getStatusCode() === 409, $e->getStatusCode());
            [$already, $state] = [true, DB::table('links')->where('id', $id)->value('state')];
        }

        return redirect('/workspaces/'.$workspace.'/links')->with('notice', match (true) {
            $state === 'revealed' => 'This link had already been opened and read; nothing was burned.',
            $already || ! in_array($state, ['burned', 'cancelled'], true) => 'This link had already ended ('.$state.'); nothing was burned.',
            $state === 'burned' => 'Link burned. It can no longer be opened.',
            default => 'Request cancelled. The link was never sent.',
        });
    }

    public function settings(string $workspace)
    {
        $space = $this->access->workspace($workspace, ['owner']);
        $settings = $this->links->settings($workspace);

        return view('onetimesecret', ['space' => $space, 'settings' => $settings, 'regions' => OneTimeSecret::REGIONS, 'lifetimes' => OneTimeSecret::LIFETIMES,
            'open' => $settings ? $this->links->openIn($settings) : 0]);
    }

    public function saveSettings(Request $r, string $workspace)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        $this->links->saveSettings($workspace, $r->only(['region', 'email', 'token', 'default_lifetime', 'max_lifetime', 'orphan']));

        return redirect('/workspaces/'.$workspace.'/onetimesecret')->with('notice', 'Saved. Check the account to make sure Onetime Secret accepts it.');
    }

    /** No password confirmation, like Burn: removing only takes the ability away (and deletes the token). */
    public function removeSettings(string $workspace)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->links->removeSettings($workspace);

        return redirect('/workspaces/'.$workspace.'/onetimesecret')->with('notice', 'Account removed. Links already out end with their lifetime; burn them in the Onetime Secret dashboard if needed.');
    }

    public function checkSettings(string $workspace)
    {
        $this->access->workspace($workspace, ['owner']);
        try {
            $this->links->check($workspace);
        } catch (OneTimeSecretError $e) {
            throw ValidationException::withMessages(['account' => $e->reason()]);
        }

        return redirect('/workspaces/'.$workspace.'/onetimesecret')->with('notice', 'Onetime Secret accepted the account.');
    }

    /** Allows or stops one-time links for an agent. */
    public function machine(Request $r, string $workspace, string $id)
    {
        $this->access->workspace($workspace, ['owner']);
        $this->access->recent($r);
        $d = $r->validate(['links' => 'required|in:0,1']);
        $machine = DB::table('machines')->where('id', $id)->where('workspace_id', $workspace)->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($machine, 404);
        // An executor (it may hold handoff files) never creates one-time links: separate identities, also later.
        abort_if($machine->executor && $d['links'] === '1', 409);
        // Under the agent's lock, like a new link: a request at the same moment comes wholly before or after.
        $open = DB::transaction(function () use ($id, $d, $machine, $r) {
            DB::table('machines')->where('id', $id)->lockForUpdate()->first();
            DB::table('machines')->where('id', $id)->update(['links' => $d['links'] === '1', 'updated_at' => now()]);

            // What it asked for and was not sent yet is not sent any more; what is out is named in the notice.
            return $d['links'] === '0' ? $this->links->stopFor($machine, 'user:'.$r->user()->id) : 0;
        });
        $this->access->audit($workspace, $d['links'] === '1' ? 'machine.links_allow' : 'machine.links_stop', $id);

        return redirect('/workspaces/'.$workspace.'/agents#agent-'.$id)->with('notice', match (true) {
            $d['links'] === '1' => 'One-time links allowed for '.$machine->name.'.',
            $open > 0 => 'One-time links stopped for '.$machine->name.'. '.$open.' of its links can still be opened: burn '.($open === 1 ? 'it' : 'them').' on Links if needed.',
            default => 'One-time links stopped for '.$machine->name.'.',
        });
    }
}
