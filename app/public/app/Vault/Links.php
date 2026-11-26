<?php

namespace App\Vault;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-time links through the workspace's Onetime Secret account.
 *
 * A link's content is plain text that may name an entry's fields as {{field}}: Vault puts the values in when it sends
 * the content to Onetime Secret, so whoever writes the text (a person or an agent) never handles the values.
 * People need editor rights (and the entry, when one is used). An agent needs the owner's "may create one-time links"
 * on Identities, and for an entry's values a grant of the action onetimesecret.link on that entry; that grant asks for
 * the owner's approval like any other (a request on Requests).
 *
 * MySQL keeps who, what entry, which field names, the lifetime and the state. OpenBao keeps the content until it was
 * sent (links/<id>), then the receipt, the passphrase and the link; all of it is destroyed when the link has ended.
 */
final class Links
{
    public const ACTION = 'onetimesecret.link';

    public const MAX_TEXT = 10000;

    public const PLACEHOLDER = '/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/';

    /** A link in one of these states can no longer be opened. */
    public const ENDED = ['cancelled', 'failed', 'revealed', 'burned', 'expired'];

    /** Limits per agent: open links at a time, and new links per hour. */
    private const AGENT_OPEN = 20;

    private const AGENT_HOURLY = 60;

    /** Seconds between two receipt checks of the same link. */
    private const CHECK_EVERY = 30;

    public function __construct(private Secrets $secrets, private Access $access, private OneTimeSecret $ots, private EntryTypes $types) {}

    // ---- Settings ------------------------------------------------------------------------------------------------

    public function settings(string $workspace): ?object
    {
        return DB::table('link_settings')->where('workspace_id', $workspace)->first();
    }

    /** Set up: an account and its token. */
    public function ready(string $workspace): ?object
    {
        $settings = $this->settings($workspace);

        return $settings && $settings->token_saved_at ? $settings : null;
    }

    public function saveSettings(string $workspace, array $input): void
    {
        $settings = $this->settings($workspace);
        $d = Validator::make($input, [
            'region' => 'required|in:'.implode(',', array_keys(OneTimeSecret::REGIONS)),
            'email' => 'required|email|max:200',
            'token' => ($settings?->token_saved_at ? 'nullable' : 'required').'|string|min:8|max:200',
            'default_lifetime' => 'required|integer|in:'.implode(',', array_keys(OneTimeSecret::LIFETIMES)),
            'max_lifetime' => 'required|integer|in:'.implode(',', array_keys(OneTimeSecret::LIFETIMES)),
        ])->validate();
        if ((int) $d['default_lifetime'] > (int) $d['max_lifetime']) {
            throw ValidationException::withMessages(['default_lifetime' => 'The default lifetime cannot be longer than the longest lifetime.']);
        }
        $d['token'] = isset($d['token']) ? trim($d['token']) : null;
        // Links made in the current account can be checked and burned only while it stays: moving to another region or
        // email with links still open needs an explicit "Change anyway".
        $open = $settings ? $this->openIn($settings) : 0;
        if ($open && ($settings->region !== $d['region'] || $settings->email !== strtolower($d['email'])) && ($input['orphan'] ?? null) !== '1') {
            throw ValidationException::withMessages(['email' => $open.' one-time '.Str::plural('link', $open).' made in the current account '.($open === 1 ? 'is' : 'are')
                .' still open. Burn them on Links first, or tick Change anyway: Vault then cannot check or burn them.']);
        }
        if (! empty($d['token'])) {
            // The new token is written first; only then do the older versions go, so a failure keeps the one that works.
            $versions = array_keys(array_filter($this->secrets->versions('settings/'.$workspace) ?? [], fn ($v) => empty($v['destroyed'])));
            $this->secrets->put('settings/'.$workspace, ['token' => $d['token']]);
            $this->secrets->destroyVersions('settings/'.$workspace, $versions);
        }
        DB::table('link_settings')->updateOrInsert(['workspace_id' => $workspace], [
            'region' => $d['region'], 'email' => strtolower($d['email']), 'default_lifetime' => (int) $d['default_lifetime'],
            'max_lifetime' => (int) $d['max_lifetime'], 'token_saved_at' => ! empty($d['token']) ? now() : $settings?->token_saved_at,
            'created_at' => $settings?->created_at ?? now(), 'updated_at' => now(),
        ]);
        $this->access->audit($workspace, 'links.settings', $workspace);
    }

    /** Links of this account that can still be opened or are on their way. */
    public function openIn(object $settings): int
    {
        return DB::table('links')->where('workspace_id', $settings->workspace_id)->where('region', $settings->region)->where('account', $settings->email)
            ->whereIn('state', ['pending', 'sending', 'active', 'previewed'])->count();
    }

    /** Removes the account: its token from OpenBao and its settings. Links already out end with their lifetime. */
    public function removeSettings(string $workspace): void
    {
        abort_unless($this->settings($workspace), 404);
        $this->secrets->destroy('settings/'.$workspace);
        DB::table('link_settings')->where('workspace_id', $workspace)->delete();
        $this->access->audit($workspace, 'links.remove', $workspace);
    }

    /** Signs in to Onetime Secret with the saved account. Throws OneTimeSecretError when that fails. */
    public function check(string $workspace): void
    {
        $settings = $this->ready($workspace);
        abort_unless($settings, 409);
        $this->ots->check($settings, $this->token($workspace));
    }

    private function token(string $workspace): string
    {
        $token = $this->secrets->find('settings/'.$workspace)['token'] ?? null;
        abort_unless(is_string($token) && $token !== '', 409);

        return $token;
    }

    /** Lifetimes an author may choose: those up to the workspace's longest. */
    public static function lifetimes(object $settings): array
    {
        return array_filter(OneTimeSecret::LIFETIMES, fn ($seconds) => $seconds <= $settings->max_lifetime, ARRAY_FILTER_USE_KEY);
    }

    private function lifetime(object $settings, mixed $requested): int
    {
        if ($requested === null || $requested === '') {
            return (int) $settings->default_lifetime;
        }
        if (! isset(self::lifetimes($settings)[(int) $requested]) || (string) (int) $requested !== (string) $requested) {
            throw ValidationException::withMessages(['lifetime' => 'Choose one of the lifetimes this workspace allows.']);
        }

        return (int) $requested;
    }

    // ---- Content -------------------------------------------------------------------------------------------------

    /** Field keys the text names, in order of first use. */
    public static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** The default content for an entry: one line per field of its type, "Label: {{key}}". */
    public static function template(?object $type): string
    {
        return implode("\n", array_map(fn ($field) => $field['label'].': {{'.$field['key'].'}}', $type->fields ?? []));
    }

    /** Checks the text against the entry's type before anything is stored. */
    private function checkText(?object $entry, ?string $text, string $workspace): array
    {
        $fields = $text === null ? [] : self::placeholders($text);
        if (! $entry) {
            if ($text === null || trim($text) === '') {
                throw ValidationException::withMessages(['text' => 'Enter the content of the link.']);
            }

            return [];
        }
        $type = $this->types->find($workspace, $entry->type);
        $known = array_column($type->fields ?? [], 'key');
        if ($unknown = array_diff($fields, $known)) {
            throw ValidationException::withMessages(['text' => 'The entry type has no field '.implode(', ', $unknown).'. Fields: '.implode(', ', $known).'.']);
        }

        return $text === null || trim($text) === '' ? $known : $fields;
    }

    /** Puts the entry's values in place of {{field}}. Fails (422) when a named field holds no value in that version. */
    private function render(?object $entry, ?int $version, ?string $text, string $workspace): string
    {
        if (! $entry) {
            return (string) $text;
        }
        $values = $this->secrets->read('entries/'.$entry->id, $version);
        $type = $this->types->find($workspace, $entry->type);
        if ($text === null || trim($text) === '') {
            $text = self::template($type);
        }
        $missing = [];
        $out = preg_replace_callback(self::PLACEHOLDER, function ($m) use ($values, &$missing) {
            $value = $values[$m[1]] ?? null;
            if (! is_string($value) || $value === '') {
                $missing[] = $m[1];

                return '';
            }

            return $value;
        }, $text);
        if ($missing) {
            throw ValidationException::withMessages(['text' => 'The entry has no value for '.implode(', ', array_unique($missing)).'.']);
        }

        return $out;
    }

    private function input(array $input): array
    {
        $d = Validator::make($input, [
            'text' => 'nullable|string|max:'.self::MAX_TEXT,
            'lifetime' => 'nullable',
            'passphrase' => 'nullable|string|max:200',
            'note' => 'nullable|string|max:120',
        ])->validate();

        return [
            'text' => isset($d['text']) ? str_replace("\r\n", "\n", $d['text']) : null,
            'passphrase' => isset($d['passphrase']) && $d['passphrase'] !== '' ? $d['passphrase'] : null,
            'note' => isset($d['note']) && trim($d['note']) !== '' ? trim($d['note']) : null,
            'lifetime' => $d['lifetime'] ?? null,
        ];
    }

    // ---- People --------------------------------------------------------------------------------------------------

    /** A person makes a link now. Returns [link row, url]; the url is shown once and never again. */
    public function createByUser(string $workspace, string $user, array $input): array
    {
        $settings = $this->ready($workspace);
        abort_unless($settings, 409);
        $entry = null;
        if (! empty($input['entry_id'])) {
            abort_unless(is_string($input['entry_id']) && Str::isUuid($input['entry_id']), 404);
            $entry = $this->access->entry($input['entry_id'], ['owner', 'editor']);
            abort_unless($entry->workspace_id === $workspace, 404);
            if ($entry->state !== 'active') {
                throw ValidationException::withMessages(['entry_id' => 'Only an active entry can be shared.']);
            }
        }
        $d = $this->input($input);
        $lifetime = $this->lifetime($settings, $d['lifetime']);
        $fields = $this->checkText($entry, $d['text'], $workspace);
        $content = $this->render($entry, $entry ? (int) $entry->version : null, $d['text'], $workspace);
        $id = (string) Str::uuid();
        // The row comes first ("sending"), so a link that reached Onetime Secret is never unknown to Vault.
        DB::table('links')->insert(['id' => $id, 'workspace_id' => $workspace, 'entry_id' => $entry?->id, 'version' => $entry ? (int) $entry->version : null,
            'user_id' => $user, 'note' => $d['note'], 'fields' => json_encode($fields), 'lifetime' => $lifetime, 'passphrase' => $d['passphrase'] !== null,
            'state' => 'sending', 'region' => $settings->region, 'account' => $settings->email, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $sent = $this->deliver(DB::table('links')->where('id', $id)->first(), $settings, $content, $d['passphrase']);
        } catch (OneTimeSecretError $e) {
            if (self::refused($e)) {
                DB::table('links')->where('id', $id)->delete();
            }
            throw ValidationException::withMessages(['text' => $e->reason()]);
        }
        $this->access->audit($workspace, 'link.create', $id);

        return [DB::table('links')->where('id', $id)->first(), $sent['url']];
    }

    /**
     * Sends a link whose row is "sending": conceal, keep the receipt, then "active" with the lifetime the service
     * applied. Any failure ends the link as failed (a link that was sent but could not be kept is burned first).
     */
    private function deliver(object $link, object $settings, #[\SensitiveParameter] string $content, #[\SensitiveParameter] ?string $passphrase): array
    {
        try {
            $token = $this->token($link->workspace_id);
            $sent = $this->ots->conceal($settings, $token, $content, (int) $link->lifetime, $passphrase);
        } catch (\Throwable $e) {
            $this->end($link, 'failed');
            throw $e instanceof OneTimeSecretError ? $e : new OneTimeSecretError(0);
        }
        try {
            // A person sees the link once, on the page that made it; only an agent's link is kept, to answer its status.
            $this->secrets->put('links/'.$link->id, ['receipt' => $sent['receipt'], 'passphrase' => $passphrase] + ($link->machine_id ? ['url' => $sent['url']] : []));
        } catch (\Throwable) {
            // A link Vault could not follow or take back must not stay out.
            try {
                $this->ots->burn($settings, $token, $sent['receipt'], $passphrase);
            } catch (\Throwable) {
                // Its lifetime still ends it.
            }
            $this->end($link, 'failed');
            throw new OneTimeSecretError(0);
        }
        $taken = DB::table('links')->where('id', $link->id)->where('state', 'sending')->update(['state' => 'active', 'lifetime' => $sent['ttl'],
            'expires_at' => now()->addSeconds($sent['ttl']), 'checked_at' => now(), 'updated_at' => now()]);
        if (! $taken && in_array(DB::table('links')->where('id', $link->id)->value('state'), self::ENDED, true)) {
            // Ended meanwhile (it took too long): what was sent goes too.
            try {
                $this->ots->burn($settings, $token, $sent['receipt'], $passphrase);
            } catch (\Throwable) {
                // Its lifetime still ends it.
            }
            $this->purge($link->id);
            throw new OneTimeSecretError(0);
        }

        return $sent;
    }

    /** The workspace's account if the link was made in it, else null (the account was changed since). */
    private function accountOf(object $link): ?object
    {
        $settings = $this->ready($link->workspace_id);

        return $settings && $settings->region === $link->region && $settings->email === $link->account ? $settings : null;
    }

    /**
     * Asks Onetime Secret for the state of the workspace's open links (at most $limit, the longest unchecked first).
     * Returns [checked, not checked]. Stops at the first refusal of the account or a rate limit.
     */
    public function refresh(string $workspace, int $limit = 50): array
    {
        if (! $this->ready($workspace)) {
            return [0, 0];
        }
        $this->settle($workspace);
        $settings = $this->ready($workspace);
        // Only links of the current account can be asked about; links of an earlier one wait for their lifetime.
        $open = DB::table('links')->where('workspace_id', $workspace)->whereIn('state', ['active', 'previewed'])
            ->where('region', $settings->region)->where('account', $settings->email)
            ->orderByRaw('checked_at IS NULL DESC')->orderBy('checked_at')->limit($limit)->get();
        $checked = 0;
        // At most 20 seconds per round; a refusal, a rate limit, no answer or a server error ends it at once.
        $until = microtime(true) + 20;
        foreach ($open as $link) {
            try {
                $this->checkLink($link);
                $checked++;
            } catch (OneTimeSecretError $e) {
                if ($e->status === 0 || $e->status >= 500 || in_array($e->status, [401, 403, 429], true)) {
                    break;
                }
            }
            if (microtime(true) >= $until) {
                break;
            }
        }

        return [$checked, $open->count() - $checked];
    }

    /** One receipt check. Throws OneTimeSecretError when the service cannot say (or the account changed: status -1). */
    private function checkLink(object $link): string
    {
        // Tried: the next round starts with the links that were not, also when this one cannot be checked.
        DB::table('links')->where('id', $link->id)->update(['checked_at' => now()]);
        if ($link->expires_at && strtotime($link->expires_at) <= time()) {
            return $this->end($link, $this->finalState($link) ?? throw new OneTimeSecretError(0));
        }
        $settings = $this->accountOf($link) ?? throw new OneTimeSecretError(-1);
        $stored = $this->secrets->find('links/'.$link->id);
        if (! isset($stored['receipt'])) {
            throw new OneTimeSecretError(-1);
        }
        $state = $this->ots->state($settings, $this->token($link->workspace_id), $stored['receipt']);
        if (in_array($state, self::ENDED, true)) {
            return $this->end($link, $state);
        }
        // Only an open link changes here; an end recorded meanwhile stays.
        if (DB::table('links')->where('id', $link->id)->whereIn('state', ['active', 'previewed'])->where('state', '!=', $state)->update(['state' => $state, 'updated_at' => now()])) {
            $this->noted($link, $state);
        }

        return DB::table('links')->where('id', $link->id)->value('state');
    }

    /**
     * How a link whose lifetime is over ended: the receipt outlives the secret, so it can still say "revealed" (read
     * before the end) or "burned". Null while the service cannot say: the receipt is kept and asked again, for up to
     * a week after the end; a link Vault can no longer ask about (an earlier account, no receipt) is "expired".
     */
    private function finalState(object $link): ?string
    {
        $settings = $this->accountOf($link);
        try {
            $receipt = $settings ? ($this->secrets->find('links/'.$link->id)['receipt'] ?? null) : null;
            if (! $receipt) {
                return 'expired';
            }
            $state = $this->ots->state($settings, $this->token($link->workspace_id), $receipt);
        } catch (\Throwable) {
            return strtotime((string) $link->expires_at) <= time() - 7 * 86400 ? 'expired' : null;
        }

        return in_array($state, ['revealed', 'burned'], true) ? $state : 'expired';
    }

    /** Opening and reading a link go to the activity log, so a read is on record even if nobody looks at Links. */
    private function noted(object $link, string $state): void
    {
        if (in_array($state, ['previewed', 'revealed'], true) && $link->state !== $state) {
            $this->access->audit($link->workspace_id, $state === 'revealed' ? 'link.read' : 'link.previewed', $link->id, 'ok', 'system');
        }
    }

    /**
     * Burns a link that has not been read, or cancels one waiting for approval. $actor: 'user:<id>' or 'machine:<id>'.
     * Returns the state Onetime Secret confirmed. A burn it did not confirm is a validation error and changes nothing.
     */
    public function burn(object $link, string $actor): string
    {
        abort_if(in_array($link->state, self::ENDED, true), 409);
        if (in_array($link->state, ['pending', 'reserved'], true)) {
            // Not sent: cancelled in one step, so a send that claims the link at the same moment finds nothing.
            if (DB::table('links')->where('id', $link->id)->whereIn('state', ['pending', 'reserved'])->update(['state' => 'cancelled', 'checked_at' => now(), 'updated_at' => now()])) {
                if ($link->operation_id) {
                    DB::table('operations')->where('id', $link->operation_id)->whereIn('state', ['pending', 'approved'])->update(['state' => 'revoked', 'updated_at' => now()]);
                }
                $this->purge($link->id);
                $this->access->audit($link->workspace_id, 'link.cancel', $link->id, 'ok', $actor);

                return 'cancelled';
            }
            $link = DB::table('links')->where('id', $link->id)->first();
        }
        if ($link->state === 'sending') {
            throw ValidationException::withMessages(['link' => 'This link is being sent right now. Try again in a moment.']);
        }
        abort_if(in_array($link->state, self::ENDED, true), 409);
        $settings = $this->accountOf($link);
        if (! $settings) {
            throw ValidationException::withMessages(['link' => 'This link was made in an earlier Onetime Secret account. Burn it there, or it ends with its lifetime.']);
        }
        $stored = $this->secrets->find('links/'.$link->id);
        if (! isset($stored['receipt'])) {
            throw ValidationException::withMessages(['link' => 'Vault no longer holds this link\'s receipt, so it cannot burn it. It ends with its lifetime.']);
        }
        try {
            $state = $this->ots->burn($settings, $this->token($link->workspace_id), $stored['receipt'], $stored['passphrase'] ?? null);
        } catch (OneTimeSecretError $e) {
            // Already read or ended there? Ask instead of claiming a burn.
            try {
                $state = $this->checkLink($link);
            } catch (OneTimeSecretError) {
                $state = null;
            }
            if (! in_array($state, self::ENDED, true)) {
                $this->access->audit($link->workspace_id, 'link.burn', $link->id, 'failed', $actor);
                throw ValidationException::withMessages(['link' => $e->reason()]);
            }
        }
        $state = $this->end($link, $state);
        $this->access->audit($link->workspace_id, 'link.burn', $link->id, $state === 'burned' ? 'ok' : $state, $actor);

        return $state;
    }

    /** A link has ended: record it and destroy what OpenBao held for it. */
    private function end(object $link, string $state): string
    {
        if (DB::table('links')->where('id', $link->id)->whereNotIn('state', self::ENDED)->update(['state' => $state, 'checked_at' => now(), 'updated_at' => now()])) {
            $this->noted($link, $state);
        }
        $this->purge($link->id);

        return DB::table('links')->where('id', $link->id)->value('state') ?? $state;
    }

    /** Destroys what OpenBao holds for a link; purged_at records that it worked, maintenance retries until it does. */
    private function purge(string $id): void
    {
        try {
            $this->secrets->destroy('links/'.$id);
            DB::table('links')->where('id', $id)->update(['purged_at' => now()]);
        } catch (\Throwable) {
            // Maintenance retries.
        }
    }

    /** Links waiting for an owner end with their request: denied, expired or revoked. */
    public function settle(string $workspace): void
    {
        $stale = DB::table('links')->join('operations', 'operations.id', '=', 'links.operation_id')->where('links.workspace_id', $workspace)
            ->where('links.state', 'pending')
            ->where(fn ($q) => $q->whereIn('operations.state', ['revoked', 'expired', 'failed'])->orWhere(fn ($q) => $q->where('operations.expires_at', '<=', now())->whereIn('operations.state', ['pending', 'approved'])))
            ->select('links.*', 'operations.state as operation_state')->get();
        foreach ($stale as $link) {
            $this->end($link, $link->operation_state === 'failed' ? 'failed' : 'cancelled');
        }
        // A send takes seconds. Still "sending" after two minutes: that send crashed. If its receipt was kept, the link
        // is out and is followed like any other; without a receipt nothing Vault knows of was sent.
        foreach (DB::table('links')->where('workspace_id', $workspace)->where('state', 'sending')->where('updated_at', '<=', now()->subMinutes(2))->get() as $link) {
            $this->recover($link);
        }
        // A request that crashed between taking its request_id and opening its request.
        foreach (DB::table('links')->where('workspace_id', $workspace)->where('state', 'reserved')->where('updated_at', '<=', now()->subMinutes(10))->get() as $link) {
            $this->end($link, 'failed');
        }
    }

    private function recover(object $link): void
    {
        try {
            $stored = $this->secrets->find('links/'.$link->id);
        } catch (\Throwable) {
            return;
        }
        if (! isset($stored['receipt'])) {
            $this->end($link, 'failed');

            return;
        }
        if (DB::table('links')->where('id', $link->id)->where('state', 'sending')->update(['state' => 'active', 'checked_at' => null,
            'expires_at' => \Illuminate\Support\Carbon::parse($link->updated_at)->addSeconds((int) $link->lifetime), 'updated_at' => now()])) {
            try {
                $this->checkLink(DB::table('links')->where('id', $link->id)->first());
            } catch (OneTimeSecretError) {
                // Checked on the next round.
            }
        }
    }

    /** Every few minutes (vault:links): ends what is over and asks Onetime Secret about the open links of each workspace. */
    public function sweep(): int
    {
        $this->maintenance();
        $checked = 0;
        foreach (DB::table('link_settings')->whereNotNull('token_saved_at')->pluck('workspace_id') as $workspace) {
            $checked += $this->refresh($workspace)[0];
        }

        return $checked;
    }

    /** An owner stopped one-time links for an agent: its requests that were not sent are cancelled with their links. */
    public function stopFor(object $machine, string $actor): int
    {
        $waiting = DB::table('links')->where('machine_id', $machine->id)->whereIn('state', ['pending', 'reserved'])->get();
        foreach ($waiting as $link) {
            if (DB::table('links')->where('id', $link->id)->whereIn('state', ['pending', 'reserved'])->update(['state' => 'cancelled', 'checked_at' => now(), 'updated_at' => now()])) {
                DB::table('operations')->where('id', $link->operation_id)->whereIn('state', ['pending', 'approved'])->update(['state' => 'revoked', 'updated_at' => now()]);
                $this->purge($link->id);
                $this->access->audit($link->workspace_id, 'link.cancel', $link->id, 'ok', $actor);
            }
        }

        return DB::table('links')->where('machine_id', $machine->id)->whereIn('state', ['sending', 'active', 'previewed'])->count();
    }

    /** Links holding an entry's values that can still be opened (for the notice after archiving or destroying it). */
    public static function openForEntry(string $entry): int
    {
        return DB::table('links')->where('entry_id', $entry)->whereIn('state', ['sending', 'active', 'previewed'])->count();
    }

    /** Links of earlier accounts that are still open: Vault can neither check nor burn them. */
    public function elsewhere(string $workspace): int
    {
        $settings = $this->ready($workspace);
        $query = DB::table('links')->where('workspace_id', $workspace)->whereIn('state', ['active', 'previewed']);

        return $settings ? (clone $query)->where(fn ($q) => $q->where('region', '!=', $settings->region)->orWhere('account', '!=', $settings->email))->count() : $query->count();
    }

    /** Links of an agent that can still be opened (for the notice after Stop or Revoke). */
    public static function openOf(string $machine): int
    {
        return DB::table('links')->where('machine_id', $machine)->whereIn('state', ['sending', 'active', 'previewed'])->count();
    }

    /** Nightly: links whose lifetime is over end; what OpenBao still holds for ended links is destroyed. */
    public function maintenance(): int
    {
        $count = 0;
        // At most a minute of asking per run; the rest waits for the next one.
        $until = microtime(true) + 60;
        foreach (DB::table('links')->whereIn('state', ['active', 'previewed'])->where('expires_at', '<=', now())->orderBy('expires_at')->limit(200)->get() as $link) {
            if ($state = $this->finalState($link)) {
                $this->end($link, $state);
                $count++;
            }
            if (microtime(true) >= $until) {
                break;
            }
        }
        foreach (DB::table('links')->whereIn('state', ['pending', 'sending', 'reserved'])->pluck('workspace_id')->unique() as $workspace) {
            $this->settle($workspace);
        }
        foreach (DB::table('links')->whereIn('state', self::ENDED)->whereNull('purged_at')->pluck('id') as $id) {
            $this->purge($id);
        }

        return $count;
    }

    // ---- Agents --------------------------------------------------------------------------------------------------

    /** What an agent may do with links: allowed, the lifetimes, and the entries it may use with their field keys. */
    public function options(object $machine): array
    {
        $settings = $this->ready($machine->workspace_id);
        if (! $machine->links || ! $settings) {
            return ['allowed' => false, 'lifetimes' => [], 'default_lifetime' => null, 'entries' => []];
        }
        $entries = app(Operations::class)->grantRows($machine)->where('grants.action', self::ACTION)
            ->select('entries.id', 'entries.alias', 'entries.type', 'grants.approval')->orderBy('entries.alias')->limit(100)->get();
        $types = $this->types->all($machine->workspace_id)->keyBy('key');

        return ['allowed' => true, 'lifetimes' => array_keys(self::lifetimes($settings)), 'default_lifetime' => (int) $settings->default_lifetime,
            'entries' => $entries->map(fn ($e) => ['id' => $e->id, 'alias' => $e->alias, 'fields' => array_column($types[$e->type]->fields ?? [], 'key'),
                'approval' => true])->values()->all()];
    }

    /** Onetime Secret said no (it did not make a secret), as opposed to not answering. */
    private static function refused(OneTimeSecretError $e): bool
    {
        return in_array($e->status, [400, 401, 403, 422, 429], true);
    }

    /** The HTTP status an agent gets when Onetime Secret did not make its link. */
    private static function agentStatus(OneTimeSecretError $e): int
    {
        return match (true) {
            $e->entitlement() || in_array($e->status, [400, 422], true) => 422,
            $e->status === 429 => 429,
            default => 503,
        };
    }

    /** The content an owner approves, and the passphrase with it, sealed so a send can tell they did not change. */
    private static function seal(string $template, ?string $passphrase): string
    {
        return hash_hmac('sha256', json_encode(['seal', $template, $passphrase]), (string) config('app.key'));
    }

    /** What a retry must repeat exactly: keyed with the application key, so the digest says nothing about the content. */
    private static function digest(?string $entry, array $d): string
    {
        // The lifetime as it was asked for (null: the default), so a retry still matches after the settings change.
        return hash_hmac('sha256', json_encode([$entry, $d['text'], $d['lifetime'] === null || $d['lifetime'] === '' ? null : (string) $d['lifetime'], $d['passphrase'], $d['note']]), (string) config('app.key'));
    }

    /** A retry with this request_id: the same link when it asks for the same thing, 409 otherwise. */
    private function retry(object $machine, string $requestId, string $digest): ?array
    {
        $prior = DB::table('links')->where('machine_id', $machine->id)->where('request_id', $requestId)->first();
        if (! $prior) {
            return null;
        }
        abort_unless(hash_equals((string) $prior->digest, $digest), 409);

        return $this->status($machine, $prior->id);
    }

    /** An agent asks for a link. Without an entry it is made at once; with one it is a request an owner approves. */
    public function createByMachine(object $machine, array $input): array
    {
        abort_unless($machine->links, 403);
        $settings = $this->ready($machine->workspace_id);
        abort_unless($settings, 409);
        $requestId = $input['request_id'] ?? null;
        abort_unless(is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $requestId), 422);
        $entryId = $input['entry_id'] ?? null;
        abort_unless($entryId === null || (is_string($entryId) && Str::isUuid($entryId)), 422);
        // Attempts count too, refused ones included: an agent cannot spend the account's rate limit.
        $key = 'link-attempts:'.$machine->id;
        abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, self::AGENT_HOURLY * 2), 429);
        \Illuminate\Support\Facades\RateLimiter::hit($key, 3600);
        $d = $this->input($input);
        // A retry is answered before anything else is checked, so it finds its link even after the settings changed.
        $digest = self::digest($entryId, $d);
        if ($prior = $this->retry($machine, $requestId, $digest)) {
            return $prior;
        }
        $lifetime = $this->lifetime($settings, $d['lifetime']);
        $template = null;
        $fields = [];
        if ($entryId === null) {
            $this->checkText(null, $d['text'], $machine->workspace_id);
        } else {
            abort_unless(app(Operations::class)->grantRows($machine)->where('entries.id', $entryId)->where('grants.action', self::ACTION)->exists(), 404);
            $entry = DB::table('entries')->where('id', $entryId)->first();
            $this->checkText($entry, $d['text'], $machine->workspace_id);
            // What the owner approves is fixed now: the final template (every field when no text was given) and its fields.
            $template = $d['text'] === null || trim($d['text']) === '' ? self::template($this->types->find($machine->workspace_id, $entry->type)) : $d['text'];
            $fields = self::placeholders($template);
        }
        $id = (string) Str::uuid();
        // Under the agent's lock: its permission now, its limits, and the request_id taken before anything is sent or
        // stored. A concurrent retry finds this row; a Stop at the same moment comes before or after as a whole.
        try {
            DB::transaction(function () use ($machine, $id, $requestId, $digest, $d, $lifetime, $settings, $entryId, $fields, $template) {
                $current = DB::table('machines')->where('id', $machine->id)->lockForUpdate()->first();
                abort_unless($current && $current->links, 403);
                $mine = DB::table('links')->where('machine_id', $machine->id);
                abort_if((clone $mine)->whereIn('state', ['reserved', 'pending', 'sending', 'active', 'previewed'])->count() >= self::AGENT_OPEN
                    || (clone $mine)->where('created_at', '>', now()->subHour())->count() >= self::AGENT_HOURLY, 429);
                DB::table('links')->insert(['id' => $id, 'workspace_id' => $machine->workspace_id, 'machine_id' => $machine->id, 'request_id' => $requestId,
                    'digest' => $digest, 'note' => $d['note'], 'lifetime' => $lifetime, 'passphrase' => $d['passphrase'] !== null,
                    'region' => $settings->region, 'account' => $settings->email, 'entry_id' => $entryId, 'fields' => json_encode($fields),
                    'sealed' => $template === null ? null : self::seal($template, $d['passphrase']),
                    'state' => $entryId === null ? 'sending' : 'reserved', 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (QueryException) {
            return $this->retry($machine, $requestId, $digest) ?? abort(409);
        }
        if ($entryId === null) {
            try {
                $this->deliver(DB::table('links')->where('id', $id)->first(), $settings, (string) $d['text'], $d['passphrase']);
            } catch (OneTimeSecretError $e) {
                // Refused outright: nothing was made, so the row goes and the same request_id can be tried again.
                if (self::refused($e)) {
                    DB::table('links')->where('id', $id)->delete();
                }
                abort(self::agentStatus($e));
            }
            $this->access->audit($machine->workspace_id, 'link.create', $id, 'ok', 'machine:'.$machine->id);

            return $this->status($machine, $id);
        }
        try {
            // The content waits in OpenBao until an owner allows it; the values are put in only when it is sent.
            $this->secrets->put('links/'.$id, ['text' => $template, 'passphrase' => $d['passphrase']]);
            app(Operations::class)->open($machine, $entryId, self::ACTION, $requestId, function (object $op) use ($id) {
                DB::table('links')->where('id', $id)->where('state', 'reserved')->update(['version' => $op->version, 'operation_id' => $op->id,
                    'state' => 'pending', 'updated_at' => now()]);
            });
            // The request_id had already opened a request that is not this link's.
            abort_unless(DB::table('links')->where('id', $id)->where('state', 'pending')->exists(), 409);
        } catch (\Throwable $e) {
            $this->end(DB::table('links')->where('id', $id)->first(), 'failed');
            throw $e;
        }

        return $this->status($machine, $id);
    }

    /**
     * Sends a link whose request was allowed. Called once per request, by the claim in Operations::status.
     * Returns the request's final state.
     */
    public function send(object $op): string
    {
        // The link is claimed in one step: a Cancel or Stop at the same moment wins or loses as a whole.
        if (! DB::table('links')->where('operation_id', $op->id)->where('state', 'pending')->update(['state' => 'sending', 'updated_at' => now()])) {
            return 'failed';
        }
        $link = DB::table('links')->where('operation_id', $op->id)->first();
        $settings = $this->ready($link->workspace_id);
        $allowed = DB::table('machines')->where('id', $link->machine_id)->where('links', true)->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
        // Approved by an owner, request by request.
        $approved = DB::table('operations')->where('id', $op->id)->whereNotNull('approved_by')->exists();
        try {
            $stored = $this->secrets->find('links/'.$link->id);
        } catch (\Throwable) {
            $stored = null;
        }
        // The draft must be the one that was approved: the same template and the same passphrase.
        $intact = is_array($stored) && is_string($stored['text'] ?? null) && $link->sealed
            && hash_equals($link->sealed, self::seal($stored['text'], $stored['passphrase'] ?? null));
        if (! $settings || ! $allowed || ! $approved || ! $intact) {
            $this->end($link, 'failed');

            return 'failed';
        }
        // Sent now: in the account and within the longest lifetime the workspace has now.
        $lifetime = min((int) $link->lifetime, (int) $settings->max_lifetime);
        DB::table('links')->where('id', $link->id)->update(['region' => $settings->region, 'account' => $settings->email, 'lifetime' => $lifetime]);
        $link = DB::table('links')->where('id', $link->id)->first();
        try {
            $entry = DB::table('entries')->where('id', $link->entry_id)->where('state', 'active')->first();
            $content = $this->render($entry ?? throw new OneTimeSecretError(0), (int) $op->version, $stored['text'], $link->workspace_id);
            $this->deliver($link, $settings, $content, $stored['passphrase'] ?? null);
        } catch (\Throwable) {
            $this->end($link, 'failed');

            return 'failed';
        }
        $this->access->audit($link->workspace_id, 'link.create', $link->id, 'ok', 'machine:'.$link->machine_id);

        return 'succeeded';
    }

    /** The agent's view of its own link: the state, and the link itself while it can be opened and the agent may have it. */
    public function status(object $machine, string $id): array
    {
        $link = DB::table('links')->where('id', $id)->where('machine_id', $machine->id)->first();
        abort_unless($link, 404);
        if ($link->state === 'pending' && $link->operation_id) {
            app(Operations::class)->status($machine, $link->operation_id);
            $this->settle($machine->workspace_id);
        } elseif (in_array($link->state, ['active', 'previewed'], true) && (! $link->checked_at || strtotime($link->checked_at) <= time() - self::CHECK_EVERY)) {
            try {
                $this->checkLink($link);
            } catch (OneTimeSecretError) {
                // The state stays as last known.
            }
        }
        $link = DB::table('links')->where('id', $id)->first();
        $url = null;
        // The link is handed out only while the agent may have it: links allowed, and for an entry's values its grant.
        $allowed = DB::table('machines')->where('id', $machine->id)->value('links')
            && (! $link->entry_id || app(Operations::class)->grantRows($machine)->where('entries.id', $link->entry_id)->where('grants.action', self::ACTION)->exists());
        if ($allowed && in_array($link->state, ['active', 'previewed'], true)) {
            $url = $this->secrets->find('links/'.$link->id)['url'] ?? null;
        }

        return ['id' => $link->id, 'state' => $link->state === 'reserved' ? 'pending' : $link->state, 'link' => $url, 'entry_id' => $link->entry_id, 'request_id' => $link->request_id,
            'lifetime' => (int) $link->lifetime, 'expires_at' => $link->expires_at, 'request' => $link->operation_id];
    }

    public function burnByMachine(object $machine, string $id): array
    {
        $link = DB::table('links')->where('id', $id)->where('machine_id', $machine->id)->first();
        abort_unless($link, 404);
        if (! in_array($link->state, self::ENDED, true)) {
            $this->burn($link, 'machine:'.$machine->id);
        }

        return $this->status($machine, $id);
    }
}
