<?php

namespace App\Vault;

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
        if (! empty($d['token'])) {
            $this->secrets->put('settings/'.$workspace, ['token' => $d['token']]);
        }
        DB::table('link_settings')->updateOrInsert(['workspace_id' => $workspace], [
            'region' => $d['region'], 'email' => strtolower($d['email']), 'default_lifetime' => (int) $d['default_lifetime'],
            'max_lifetime' => (int) $d['max_lifetime'], 'token_saved_at' => ! empty($d['token']) ? now() : $settings?->token_saved_at,
            'created_at' => $settings?->created_at ?? now(), 'updated_at' => now(),
        ]);
        $this->access->audit($workspace, 'links.settings', $workspace);
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
        $sent = $this->conceal($workspace, $settings, $content, $lifetime, $d['passphrase']);
        $this->secrets->put('links/'.$id, ['receipt' => $sent['receipt'], 'url' => $sent['url'], 'passphrase' => $d['passphrase']]);
        DB::table('links')->insert(['id' => $id, 'workspace_id' => $workspace, 'entry_id' => $entry?->id, 'version' => $entry ? (int) $entry->version : null,
            'user_id' => $user, 'note' => $d['note'], 'fields' => json_encode($fields), 'lifetime' => $lifetime, 'passphrase' => $d['passphrase'] !== null,
            'state' => 'active', 'expires_at' => now()->addSeconds($lifetime), 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->access->audit($workspace, 'link.create', $id);

        return [DB::table('links')->where('id', $id)->first(), $sent['url']];
    }

    private function conceal(string $workspace, object $settings, #[\SensitiveParameter] string $content, int $lifetime, #[\SensitiveParameter] ?string $passphrase): array
    {
        try {
            return $this->ots->conceal($settings, $this->token($workspace), $content, $lifetime, $passphrase);
        } catch (OneTimeSecretError $e) {
            throw ValidationException::withMessages(['text' => $e->reason()]);
        }
    }

    /** Asks Onetime Secret for the state of the workspace's open links (at most $limit, the oldest check first). */
    public function refresh(string $workspace, int $limit = 50): int
    {
        $settings = $this->ready($workspace);
        if (! $settings) {
            return 0;
        }
        $this->settle($workspace);
        $open = DB::table('links')->where('workspace_id', $workspace)->whereIn('state', ['active', 'previewed'])
            ->orderBy('checked_at')->limit($limit)->get();
        $token = $open->isEmpty() ? null : $this->token($workspace);
        foreach ($open as $link) {
            $this->checkQuietly($link, $settings, $token);
        }

        return $open->count();
    }

    /** One receipt check. Throws OneTimeSecretError when the service cannot say. */
    private function checkLink(object $link, object $settings, string $token): string
    {
        if ($link->expires_at && strtotime($link->expires_at) <= time()) {
            return $this->end($link, 'expired');
        }
        $stored = $this->secrets->find('links/'.$link->id);
        if (! isset($stored['receipt'])) {
            return $this->end($link, 'expired');
        }
        $state = $this->ots->state($settings, $token, $stored['receipt']);
        if (in_array($state, self::ENDED, true)) {
            return $this->end($link, $state);
        }
        DB::table('links')->where('id', $link->id)->update(['state' => $state, 'checked_at' => now(), 'updated_at' => now()]);

        return $state;
    }

    private function checkQuietly(object $link, object $settings, string $token): void
    {
        try {
            $this->checkLink($link, $settings, $token);
        } catch (OneTimeSecretError) {
            // Leave the state as it was; the list says when it was last checked.
        }
    }

    /** Burns a link that has not been read. $actor: 'user:<id>' or 'machine:<id>'. */
    public function burn(object $link, string $actor): string
    {
        abort_if(in_array($link->state, self::ENDED, true), 409);
        if ($link->state === 'pending') {
            // Not sent yet: there is nothing to burn at Onetime Secret; the request is cancelled with the link.
            if ($link->operation_id) {
                DB::table('operations')->where('id', $link->operation_id)->whereIn('state', ['pending', 'approved'])->update(['state' => 'revoked', 'updated_at' => now()]);
            }
            $this->end($link, 'cancelled');
            $this->access->audit($link->workspace_id, 'link.burn', $link->id, 'ok', $actor);

            return 'cancelled';
        }
        $settings = $this->ready($link->workspace_id);
        abort_unless($settings, 409);
        $stored = $this->secrets->find('links/'.$link->id);
        if (isset($stored['receipt'])) {
            try {
                $this->ots->burn($settings, $this->token($link->workspace_id), $stored['receipt'], $stored['passphrase'] ?? null);
            } catch (OneTimeSecretError $e) {
                // Already read, burned or expired there: ask for the state instead of claiming a burn.
                $state = $this->checkLink($link, $settings, $this->token($link->workspace_id));
                $this->access->audit($link->workspace_id, 'link.burn', $link->id, in_array($state, self::ENDED, true) ? $state : 'failed', $actor);
                if (! in_array($state, self::ENDED, true)) {
                    throw ValidationException::withMessages(['link' => $e->reason()]);
                }

                return $state;
            }
        }
        $this->end($link, 'burned');
        $this->access->audit($link->workspace_id, 'link.burn', $link->id, 'ok', $actor);

        return 'burned';
    }

    /** A link has ended: record it and destroy what OpenBao held for it. */
    private function end(object $link, string $state): string
    {
        DB::table('links')->where('id', $link->id)->whereNotIn('state', self::ENDED)->update(['state' => $state, 'checked_at' => now(), 'updated_at' => now()]);
        try {
            $this->secrets->destroy('links/'.$link->id);
        } catch (\Throwable) {
            // Maintenance destroys it on a later run.
        }

        return DB::table('links')->where('id', $link->id)->value('state') ?? $state;
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
    }

    /** Nightly: ended or expired links lose what OpenBao held. */
    public function maintenance(): int
    {
        $count = 0;
        foreach (DB::table('links')->whereNotIn('state', self::ENDED)->where('expires_at', '<=', now())->get() as $link) {
            $this->end($link, 'expired');
            $count++;
        }
        foreach (DB::table('links')->where('state', 'pending')->pluck('workspace_id')->unique() as $workspace) {
            $this->settle($workspace);
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
                'approval' => (bool) $e->approval])->values()->all()];
    }

    /** An agent asks for a link. Without an entry it is made at once; with one it is a request on the entry's grant. */
    public function createByMachine(object $machine, array $input): array
    {
        abort_unless($machine->links, 403);
        $settings = $this->ready($machine->workspace_id);
        abort_unless($settings, 409);
        $requestId = $input['request_id'] ?? null;
        abort_unless(is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $requestId), 422);
        $entryId = $input['entry_id'] ?? null;
        abort_unless($entryId === null || (is_string($entryId) && Str::isUuid($entryId)), 422);
        $prior = DB::table('links')->where('machine_id', $machine->id)->where('request_id', $requestId)->first();
        if ($prior) {
            abort_unless($prior->entry_id === $entryId, 409);

            return $this->status($machine, $prior->id);
        }
        $d = $this->input($input);
        $lifetime = $this->lifetime($settings, $d['lifetime']);
        $id = (string) Str::uuid();
        if ($entryId === null) {
            $this->checkText(null, $d['text'], $machine->workspace_id);
            try {
                $sent = $this->ots->conceal($settings, $this->token($machine->workspace_id), (string) $d['text'], $lifetime, $d['passphrase']);
            } catch (OneTimeSecretError $e) {
                abort($e->status === 429 ? 429 : 503);
            }
            $this->secrets->put('links/'.$id, ['receipt' => $sent['receipt'], 'url' => $sent['url'], 'passphrase' => $d['passphrase']]);
            DB::table('links')->insert(['id' => $id, 'workspace_id' => $machine->workspace_id, 'machine_id' => $machine->id, 'request_id' => $requestId,
                'note' => $d['note'], 'fields' => '[]', 'lifetime' => $lifetime, 'passphrase' => $d['passphrase'] !== null,
                'state' => 'active', 'expires_at' => now()->addSeconds($lifetime), 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->access->audit($machine->workspace_id, 'link.create', $id, 'ok', 'machine:'.$machine->id);

            return $this->status($machine, $id);
        }
        $grant = app(Operations::class)->grantRows($machine)->where('entries.id', $entryId)->where('grants.action', self::ACTION)
            ->select('grants.*', 'entries.version', 'entries.type')->first();
        abort_unless($grant, 404);
        $entry = DB::table('entries')->where('id', $entryId)->first();
        $fields = $this->checkText($entry, $d['text'], $machine->workspace_id);
        // The content waits in OpenBao until the request is allowed; the values are put in only when it is sent.
        $this->secrets->put('links/'.$id, ['text' => $d['text'], 'passphrase' => $d['passphrase']]);
        try {
            app(Operations::class)->open($machine, $entryId, self::ACTION, $requestId, function (object $op) use ($id, $machine, $entryId, $requestId, $d, $fields, $lifetime) {
                DB::table('links')->insert(['id' => $id, 'workspace_id' => $machine->workspace_id, 'entry_id' => $entryId, 'version' => $op->version,
                    'operation_id' => $op->id, 'machine_id' => $machine->id, 'request_id' => $requestId, 'note' => $d['note'], 'fields' => json_encode($fields),
                    'lifetime' => $lifetime, 'passphrase' => $d['passphrase'] !== null, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            });
            // The request_id already opened a request that is not this link's.
            abort_unless(DB::table('links')->where('id', $id)->exists(), 409);
        } catch (\Throwable $e) {
            $this->secrets->destroy('links/'.$id);
            throw $e;
        }
        // An allowed request without approval is sent right here (Operations::status claims it once).
        return $this->status($machine, $id);
    }

    /**
     * Sends a link whose request was allowed. Called once per request, by the claim in Operations::status.
     * Returns the request's final state.
     */
    public function send(object $op): string
    {
        $link = DB::table('links')->where('operation_id', $op->id)->where('state', 'pending')->first();
        if (! $link) {
            return 'failed';
        }
        try {
            $settings = $this->ready($link->workspace_id) ?? throw new OneTimeSecretError(0);
            $stored = $this->secrets->find('links/'.$link->id) ?? [];
            $entry = DB::table('entries')->where('id', $link->entry_id)->first();
            $content = $this->render($entry, (int) $op->version, $stored['text'] ?? null, $link->workspace_id);
            $sent = $this->ots->conceal($settings, $this->token($link->workspace_id), $content, (int) $link->lifetime, $stored['passphrase'] ?? null);
        } catch (\Throwable) {
            $this->end($link, 'failed');

            return 'failed';
        }
        $this->secrets->put('links/'.$link->id, ['receipt' => $sent['receipt'], 'url' => $sent['url'], 'passphrase' => $stored['passphrase'] ?? null]);
        DB::table('links')->where('id', $link->id)->update(['state' => 'active', 'expires_at' => now()->addSeconds((int) $link->lifetime),
            'checked_at' => now(), 'updated_at' => now()]);
        $this->access->audit($link->workspace_id, 'link.create', $link->id, 'ok', 'machine:'.$link->machine_id);

        return 'succeeded';
    }

    /** The agent's view of its own link: the state, and the link itself while it can still be opened. */
    public function status(object $machine, string $id): array
    {
        $link = DB::table('links')->where('id', $id)->where('machine_id', $machine->id)->first();
        abort_unless($link, 404);
        if ($link->state === 'pending' && $link->operation_id) {
            app(Operations::class)->status($machine, $link->operation_id);
            $this->settle($machine->workspace_id);
        } elseif (in_array($link->state, ['active', 'previewed'], true) && (! $link->checked_at || strtotime($link->checked_at) <= time() - self::CHECK_EVERY)) {
            if ($settings = $this->ready($machine->workspace_id)) {
                $this->checkQuietly($link, $settings, $this->token($machine->workspace_id));
            }
        }
        $link = DB::table('links')->where('id', $id)->first();
        $url = null;
        if (in_array($link->state, ['active', 'previewed'], true)) {
            $url = $this->secrets->find('links/'.$link->id)['url'] ?? null;
        }

        return ['id' => $link->id, 'state' => $link->state, 'link' => $url, 'entry_id' => $link->entry_id, 'request_id' => $link->request_id,
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
